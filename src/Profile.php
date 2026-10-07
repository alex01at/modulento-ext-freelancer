<?php

declare(strict_types=1);

namespace Modulento\Freelancer;

use Modulento\Core\App;
use Modulento\Core\Support\Clock;
use Modulento\Core\Support\Money;
use PDO;

/**
 * A freelancer's own profile beyond an offer: an hourly rate, availability,
 * a short bio per language, and a list of skills. One row per provider;
 * a provider who never filled this in has none - providerSection() then
 * shows nothing on their public page.
 */
final class Profile
{
    public const AVAILABILITY = ['available', 'busy', 'paused'];
    public const MAX_SKILLS = 15;
    public const SKILL_MAX_LENGTH = 30;

    /** In minor units (cents), like every other price in the core. */
    private const MIN_RATE = 100;
    private const MAX_RATE = 10_000_000;

    /** @return array{hourly_rate: ?int, availability: string, bio: array<string, string>, skills: string[]}|null null without a profile; hourly_rate is in minor units */
    public function load(int $providerId, App $app): ?array
    {
        $stmt = $app->db->prepare('SELECT * FROM x_freelancer_profile WHERE provider_id = :id');
        $stmt->execute(['id' => $providerId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $stmt = $app->db->prepare('SELECT locale, bio FROM x_freelancer_profile_translation WHERE provider_id = :id');
        $stmt->execute(['id' => $providerId]);

        return [
            'hourly_rate' => $row['hourly_rate'] !== null ? (int) $row['hourly_rate'] : null,
            'availability' => $row['availability'],
            'bio' => $stmt->fetchAll(PDO::FETCH_KEY_PAIR),
            'skills' => $this->skills($providerId, $app),
        ];
    }

    /** @return string[] */
    public function skills(int $providerId, App $app): array
    {
        $stmt = $app->db->prepare('SELECT name FROM x_freelancer_skill WHERE provider_id = :id ORDER BY position');
        $stmt->execute(['id' => $providerId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{values: array{hourly_rate: ?int, availability: string, bio: array<string, string>, skills: string[]}, errors: string[]}
     */
    public function validate(array $input, App $app): array
    {
        $errors = [];

        $rateInput = trim((string) ($input['hourly_rate'] ?? ''));
        $rate = null;
        if ($rateInput !== '') {
            $rate = Money::parse($rateInput);
            if ($rate === null || $rate < self::MIN_RATE || $rate > self::MAX_RATE) {
                $errors[] = 'freelancer.profile.error.hourly_rate';
            }
        }

        $availability = (string) ($input['availability'] ?? '');
        if (!in_array($availability, self::AVAILABILITY, true)) {
            $errors[] = 'freelancer.profile.error.availability';
        }

        $bio = [];
        foreach ($app->locales->enabled() as $locale) {
            $text = trim(str_replace("\r\n", "\n", (string) ($input['bio'][$locale] ?? '')));
            if ($text === '') {
                continue;
            }
            if (mb_strlen($text) > 500) {
                $errors[] = 'freelancer.profile.error.bio_too_long';
                continue;
            }
            $bio[$locale] = $text;
        }

        $skills = [];
        $lines = preg_split('/[\r\n,]+/', (string) ($input['skills'] ?? '')) ?: [];
        foreach ($lines as $line) {
            $skill = mb_substr(trim($line), 0, self::SKILL_MAX_LENGTH);
            if ($skill === '' || in_array($skill, $skills, true)) {
                continue;
            }
            $skills[] = $skill;
            if (count($skills) >= self::MAX_SKILLS) {
                break;
            }
        }

        return [
            'values' => ['hourly_rate' => $rate, 'availability' => $availability, 'bio' => $bio, 'skills' => $skills],
            'errors' => array_values(array_unique($errors)),
        ];
    }

    /** @param array{hourly_rate: ?int, availability: string, bio: array<string, string>, skills: string[]} $values */
    public function save(int $providerId, array $values, App $app): void
    {
        $db = $app->db;
        $db->beginTransaction();

        $exists = (bool) $db->query("SELECT 1 FROM x_freelancer_profile WHERE provider_id = {$providerId}")->fetchColumn();
        if ($exists) {
            $stmt = $db->prepare('UPDATE x_freelancer_profile SET hourly_rate = :rate, availability = :availability, updated_at = :now WHERE provider_id = :id');
        } else {
            $stmt = $db->prepare('INSERT INTO x_freelancer_profile (provider_id, hourly_rate, availability, updated_at) VALUES (:id, :rate, :availability, :now)');
        }
        $stmt->execute(['id' => $providerId, 'rate' => $values['hourly_rate'], 'availability' => $values['availability'], 'now' => Clock::now()]);

        $db->prepare('DELETE FROM x_freelancer_profile_translation WHERE provider_id = :id')->execute(['id' => $providerId]);
        $insertBio = $db->prepare('INSERT INTO x_freelancer_profile_translation (provider_id, locale, bio) VALUES (:id, :locale, :bio)');
        foreach ($values['bio'] as $locale => $text) {
            $insertBio->execute(['id' => $providerId, 'locale' => $locale, 'bio' => $text]);
        }

        $db->prepare('DELETE FROM x_freelancer_skill WHERE provider_id = :id')->execute(['id' => $providerId]);
        $insertSkill = $db->prepare('INSERT INTO x_freelancer_skill (provider_id, name, position) VALUES (:id, :name, :position)');
        foreach ($values['skills'] as $position => $name) {
            $insertSkill->execute(['id' => $providerId, 'name' => $name, 'position' => $position]);
        }

        $db->commit();
    }
}
