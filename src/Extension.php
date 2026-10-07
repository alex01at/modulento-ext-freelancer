<?php

declare(strict_types=1);

namespace Modulento\Freelancer;

use Modulento\Core\App;
use Modulento\Core\Event\AccountDeleted;
use Modulento\Core\Extension\Extension as ExtensionContract;
use Modulento\Core\Extension\Registrar;
use Modulento\Core\Support\Router;

/**
 * Turns the catalogue into a marketplace for services: an offer of the
 * type "freelancer.service" has packages with a price, a delivery time and
 * a number of revisions, optional extras, and a note on what the provider
 * needs from the buyer. ServiceFlow describes how such an offer is ordered,
 * delivered, revised and accepted.
 *
 * On top of an offer, a provider can keep a profile of their own - hourly
 * rate, availability, a short bio, skills and a portfolio - shown as a
 * block on their public page (Registrar::providerSection()) and edited
 * from a page of its own, linked from the account area
 * (Registrar::accountLink()).
 */
final class Extension implements ExtensionContract
{
    public function register(Registrar $registrar): void
    {
        $type = new ServiceType();
        $registrar->offerType($type);
        $registrar->orderFlow(new ServiceFlow($type));

        $registrar->routes(function (Router $router): void {
            $router->get('/freelancer/profile', [ProfileController::class, 'edit']);
            $router->post('/freelancer/profile', [ProfileController::class, 'save']);
            $router->post('/freelancer/profile/portfolio', [ProfileController::class, 'uploadPortfolio']);
            $router->post('/freelancer/profile/portfolio/{item}/delete', [ProfileController::class, 'deletePortfolio']);
            $router->get('/freelancer/portfolio/{account}/{file}', [ProfileController::class, 'portfolioImage'], Router::PUBLIC);
        });

        $registrar->accountLink('freelancer.nav.profile', '/freelancer/profile');

        $registrar->providerSection('@freelancer/provider_profile.twig', function (array $provider, App $app): array {
            $profile = (new Profile())->load((int) $provider['id'], $app);
            if ($profile === null) {
                return [];
            }

            $locale = $app->translator->locale();
            $default = $app->locales->default();
            $portfolio = (new PortfolioImages($app->db, self::uploadDir($app)))->ofProvider((int) $provider['id']);
            $pick = fn (array $texts) => $texts[$locale] ?? $texts[$default] ?? (array_values($texts)[0] ?? []);

            return [
                'freelancer' => $profile,
                'bio' => $profile['bio'][$locale] ?? $profile['bio'][$default] ?? '',
                'currency' => $app->offers->currency(),
                'portfolio' => array_map(fn (array $item) => [
                    'urls' => PortfolioImages::urls($item, (int) $provider['account_id']),
                    'text' => $pick($item['texts']),
                ], $portfolio),
            ];
        });

        // The account's portfolio pictures went with it; the provider row
        // (and everything that cascades from it) is already gone by now.
        $registrar->listen(AccountDeleted::class, function (AccountDeleted $event, App $app): void {
            (new PortfolioImages($app->db, self::uploadDir($app)))->deleteAllForAccount($event->accountId);
        });
    }

    private static function uploadDir(App $app): string
    {
        $config = $app->config;

        return ($config['app']['uploads'] ?? $config['app']['root'] . '/var/uploads') . '/freelancer-portfolio';
    }
}
