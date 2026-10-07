<?php

declare(strict_types=1);

namespace Modulento\Freelancer;

use Modulento\Core\App;
use Modulento\Core\Support\Clock;
use PDO;

/**
 * A freelancer's portfolio pictures. Like the core's offer pictures, an
 * upload is never stored as it arrived: it is decoded and written anew,
 * which removes embedded data and oversized originals. Kept under the
 * owning account's id, not the provider's, so AccountDeleted can remove
 * the whole folder in one step - see Extension::register().
 */
final class PortfolioImages
{
    public const MAX_PER_PROVIDER = 12;
    public const MAX_BYTES = 8 * 1024 * 1024;

    private const MAX_PIXELS = 40_000_000;
    private const LARGE_EDGE = 1200;
    private const THUMB_EDGE = 360;
    private const ACCEPTED = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    public function __construct(private PDO $db, private string $uploadDir)
    {
    }

    /** @return array<int, array> */
    public function ofProvider(int $providerId): array
    {
        $stmt = $this->db->prepare('SELECT * FROM x_freelancer_portfolio_item WHERE provider_id = :id ORDER BY position, id');
        $stmt->execute(['id' => $providerId]);

        $items = $stmt->fetchAll();
        if ($items === []) {
            return [];
        }

        $stmt = $this->db->prepare(
            'SELECT t.* FROM x_freelancer_portfolio_translation t JOIN x_freelancer_portfolio_item i ON i.id = t.item_id WHERE i.provider_id = :id'
        );
        $stmt->execute(['id' => $providerId]);
        $texts = [];
        foreach ($stmt->fetchAll() as $row) {
            $texts[(int) $row['item_id']][$row['locale']] = ['title' => $row['title'], 'description' => $row['description']];
        }

        return array_map(fn (array $item) => $item + ['texts' => $texts[(int) $item['id']] ?? []], $items);
    }

    /**
     * @param array{tmp_name?: string, error?: int, size?: int} $upload one entry of $_FILES
     * @param array<string, array{title: string, description: string}> $texts by locale
     * @return string|null language key of the problem, null on success
     */
    public function add(int $providerId, int $accountId, array $upload, array $texts): ?string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagecreatetruecolor')) {
            return 'freelancer.profile.error.portfolio_unavailable';
        }
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_file($upload['tmp_name'] ?? '')) {
            return in_array($upload['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'freelancer.profile.error.portfolio_too_large'
                : 'freelancer.profile.error.portfolio_upload';
        }
        if (filesize($upload['tmp_name']) > self::MAX_BYTES) {
            return 'freelancer.profile.error.portfolio_too_large';
        }
        if (count($this->ofProvider($providerId)) >= self::MAX_PER_PROVIDER) {
            return 'freelancer.profile.error.portfolio_too_many';
        }

        // What the file is is read from its content, never from the name
        // or the type the browser claims.
        $info = @getimagesize($upload['tmp_name']);
        if ($info === false || !in_array($info[2], self::ACCEPTED, true)) {
            return 'freelancer.profile.error.portfolio_type';
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            return 'freelancer.profile.error.portfolio_dimensions';
        }

        $source = @imagecreatefromstring((string) file_get_contents($upload['tmp_name']));
        if ($source === false) {
            return 'freelancer.profile.error.portfolio_type';
        }

        $extension = function_exists('imagewebp') ? 'webp' : 'jpg';
        $name = bin2hex(random_bytes(16));
        $dir = $this->uploadDir . '/' . $accountId;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return 'freelancer.profile.error.portfolio_storage';
        }

        $large = $this->resized($source, self::LARGE_EDGE);
        $thumb = $this->resized($source, self::THUMB_EDGE);
        $written = $this->write($large, "{$dir}/{$name}.{$extension}", $extension)
            && $this->write($thumb, "{$dir}/{$name}_thumb.{$extension}", $extension);
        imagedestroy($source);
        imagedestroy($large);
        imagedestroy($thumb);

        if (!$written) {
            @unlink("{$dir}/{$name}.{$extension}");
            @unlink("{$dir}/{$name}_thumb.{$extension}");

            return 'freelancer.profile.error.portfolio_storage';
        }

        $stored = $name . '.' . $extension;
        $stmt = $this->db->prepare(
            'INSERT INTO x_freelancer_portfolio_item (provider_id, position, image_name, created_at) VALUES (:provider, :position, :name, :now)'
        );
        $stmt->execute(['provider' => $providerId, 'position' => count($this->ofProvider($providerId)), 'name' => $stored, 'now' => Clock::now()]);
        $itemId = (int) $this->db->lastInsertId();

        $insertText = $this->db->prepare('INSERT INTO x_freelancer_portfolio_translation (item_id, locale, title, description) VALUES (:id, :locale, :title, :description)');
        foreach ($texts as $locale => $text) {
            $insertText->execute(['id' => $itemId, 'locale' => $locale] + $text);
        }

        return null;
    }

    public function delete(int $providerId, int $accountId, int $itemId): void
    {
        $stmt = $this->db->prepare('SELECT * FROM x_freelancer_portfolio_item WHERE id = :id AND provider_id = :provider');
        $stmt->execute(['id' => $itemId, 'provider' => $providerId]);
        $item = $stmt->fetch();
        if (!$item) {
            return;
        }

        $this->db->prepare('DELETE FROM x_freelancer_portfolio_item WHERE id = :id')->execute(['id' => $itemId]);
        $this->unlinkFiles($accountId, $item['image_name']);
    }

    /** Removes every file of an account - the rows go with the provider/account on their own. */
    public function deleteAllForAccount(int $accountId): void
    {
        $dir = $this->uploadDir . '/' . $accountId;
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $file) {
            if ($file !== '.' && $file !== '..') {
                @unlink($dir . '/' . $file);
            }
        }
        @rmdir($dir);
    }

    /** The stored file for a request, or null - only names this class created can match. */
    public function path(int $accountId, string $file): ?string
    {
        if (preg_match('/^[0-9a-f]{32}(_thumb)?\.(webp|jpg)$/', $file) !== 1) {
            return null;
        }
        $path = $this->uploadDir . '/' . $accountId . '/' . $file;

        return is_file($path) ? $path : null;
    }

    /** Paths (below the extension's own public route) of an item row, for templates. */
    public static function urls(array $item, int $accountId): array
    {
        $base = '/freelancer/portfolio/' . $accountId . '/';
        $dot = strrpos($item['image_name'], '.');
        $stem = $dot !== false ? substr($item['image_name'], 0, $dot) : $item['image_name'];
        $extension = $dot !== false ? substr($item['image_name'], $dot + 1) : '';

        return [
            'id' => (int) $item['id'],
            'large' => $base . $stem . '.' . $extension,
            'thumb' => $base . $stem . '_thumb.' . $extension,
        ];
    }

    private function unlinkFiles(int $accountId, string $imageName): void
    {
        $dot = strrpos($imageName, '.');
        $stem = $dot !== false ? substr($imageName, 0, $dot) : $imageName;
        $extension = $dot !== false ? substr($imageName, $dot + 1) : '';
        $base = $this->uploadDir . '/' . $accountId . '/' . $stem;
        @unlink($base . '.' . $extension);
        @unlink($base . '_thumb.' . $extension);
    }

    private function resized(\GdImage $source, int $maxEdge): \GdImage
    {
        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $maxEdge / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($newWidth, $newHeight);
        imagefill($target, 0, 0, imagecolorallocate($target, 255, 255, 255));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $target;
    }

    private function write(\GdImage $image, string $path, string $extension): bool
    {
        return $extension === 'webp' ? imagewebp($image, $path, 82) : imagejpeg($image, $path, 85);
    }
}
