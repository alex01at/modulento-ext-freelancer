<?php

declare(strict_types=1);

namespace Modulento\Freelancer;

use Modulento\Core\Controller\Controller;
use Modulento\Core\Support\Money;
use Modulento\Core\Support\Session;

/**
 * A freelancer's own profile: hourly rate, availability, bio, skills and
 * portfolio. Everything here needs a provider profile of the account's own
 * first - the offer side of things, which this builds on.
 */
final class ProfileController extends Controller
{
    public function edit(array $params): void
    {
        $provider = $this->ownProvider();
        if ($provider === null) {
            return;
        }

        $this->renderForm($provider, []);
    }

    public function save(array $params): void
    {
        $provider = $this->ownProvider();
        if ($provider === null) {
            return;
        }

        $profile = new Profile();
        $result = $profile->validate($_POST, $this->app);
        if ($result['errors'] !== []) {
            $this->renderForm($provider, array_map(fn (string $key) => $this->trans($key), $result['errors']));
            return;
        }

        $profile->save((int) $provider['id'], $result['values'], $this->app);
        Session::flash('success', $this->trans('freelancer.profile.saved'));
        $this->redirect('/freelancer/profile');
    }

    public function uploadPortfolio(array $params): void
    {
        $provider = $this->ownProvider();
        if ($provider === null) {
            return;
        }

        $texts = [];
        foreach ($this->app->locales->enabled() as $locale) {
            $title = trim((string) ($_POST['text'][$locale]['title'] ?? ''));
            $description = trim((string) ($_POST['text'][$locale]['description'] ?? ''));
            if ($title !== '' || $description !== '') {
                $texts[$locale] = ['title' => mb_substr($title, 0, 100), 'description' => mb_substr($description, 0, 500)];
            }
        }

        $images = new PortfolioImages($this->app->db, $this->uploadDir());
        $problem = $images->add(
            (int) $provider['id'],
            (int) $provider['account_id'],
            is_array($_FILES['image'] ?? null) ? $_FILES['image'] : [],
            $texts
        );

        Session::flash($problem === null ? 'success' : 'error', $this->trans($problem ?? 'freelancer.profile.portfolio_saved', [
            'max' => PortfolioImages::MAX_PER_PROVIDER, 'megabytes' => intdiv(PortfolioImages::MAX_BYTES, 1024 * 1024),
        ]));
        $this->redirect('/freelancer/profile');
    }

    public function deletePortfolio(array $params): void
    {
        $provider = $this->ownProvider();
        if ($provider === null) {
            return;
        }

        (new PortfolioImages($this->app->db, $this->uploadDir()))->delete((int) $provider['id'], (int) $provider['account_id'], (int) $params['item']);
        Session::flash('success', $this->trans('freelancer.profile.portfolio_deleted'));
        $this->redirect('/freelancer/profile');
    }

    /** Public: a portfolio picture, like the core's own /media/offers/... */
    public function portfolioImage(array $params): void
    {
        $file = (new PortfolioImages($this->app->db, $this->uploadDir()))->path((int) $params['account'], $params['file']);
        if ($file === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo '404';
            return;
        }

        header('Content-Type: ' . (str_ends_with($file, '.webp') ? 'image/webp' : 'image/jpeg'));
        header('Cache-Control: public, max-age=31536000, immutable');
        header("Content-Security-Policy: default-src 'none'");
        header('Content-Length: ' . filesize($file));
        readfile($file);
    }

    private function renderForm(array $provider, array $errors): void
    {
        $locale = $this->app->translator->locale();
        $profile = (new Profile())->load((int) $provider['id'], $this->app) ?? [
            'hourly_rate' => null, 'availability' => 'available', 'bio' => [], 'skills' => [],
        ];
        $portfolio = (new PortfolioImages($this->app->db, $this->uploadDir()))->ofProvider((int) $provider['id']);

        $this->render('@freelancer/profile_edit.twig', [
            'errors' => $errors,
            'availability' => Profile::AVAILABILITY,
            'profile' => $profile,
            'hourly_rate_input' => $profile['hourly_rate'] !== null ? Money::input($profile['hourly_rate'], $locale) : '',
            'skills_text' => implode("\n", $profile['skills']),
            'locales' => $this->app->locales->enabled(),
            'portfolio' => array_map(fn (array $item) => [
                'id' => (int) $item['id'],
                'urls' => PortfolioImages::urls($item, (int) $provider['account_id']),
                'texts' => $item['texts'],
            ], $portfolio),
            'max_portfolio' => PortfolioImages::MAX_PER_PROVIDER,
            'max_skills' => Profile::MAX_SKILLS,
        ]);
    }

    /** The logged-in account's own provider, or null (and a response already sent) without one. */
    private function ownProvider(): ?array
    {
        $provider = $this->app->providers->findByAccount($this->app->auth->account()['id']);
        if ($provider === null) {
            Session::flash('error', $this->trans('freelancer.profile.error.no_provider'));
            $this->redirect('/account/provider');
            return null;
        }

        return $provider;
    }

    private function uploadDir(): string
    {
        $config = $this->app->config;

        return ($config['app']['uploads'] ?? $config['app']['root'] . '/var/uploads') . '/freelancer-portfolio';
    }
}
