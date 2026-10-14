<?php
/**
 * Catalog image file → absolute, resized (cached) thumbnail URL for emails, e.g.
 * https://d1f0kjhjeqrfvd.cloudfront.net/media/catalog/product/cache/<hash>/S/-/S-WNPWC.jpg
 *
 * Payloads are built in cron/CLI, so the store's frontend is emulated: the resize parameters come
 * from the storefront theme's view.xml and the host from the store's configured media base URL
 * (never hardcoded). $imageId must be an id in that view.xml — Magento only generates cache files
 * for configured sizes (catalog:images:resize, or get.php on first request), so an ad-hoc size
 * would 404. Default `wishlist_small_image` = 113×113 in Luma (inherited by Lucenttheme/Innovations),
 * ≈2× the 56 px thumbnail in the templates. Change it in di.xml.
 *
 * Never throws: if the resized URL can't be built, falls back to the original image's media URL.
 */

declare(strict_types=1);

namespace ArtLounge\BentoEvents\Model\Payload;

use Magento\Catalog\Model\Product\Image\UrlBuilder;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class ThumbnailUrlBuilder
{
    public function __construct(
        private readonly UrlBuilder $urlBuilder,
        private readonly Emulation $emulation,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger,
        private readonly string $imageId = 'wishlist_small_image'
    ) {
    }

    /**
     * @param array<int|string,?string> $files key => catalog file ('/a/b/ab.jpg') or null
     * @return array<int|string,?string> key => absolute URL, or null when there is no file
     */
    public function urls(array $files, int $storeId): array
    {
        $out = array_map(static fn () => null, $files);
        $files = array_filter($files, static fn ($f) => is_string($f) && $f !== '');
        if (!$files) {
            return $out;
        }

        $emulating = false;
        try {
            $this->emulation->startEnvironmentEmulation($storeId, 'frontend', true);
            $emulating = true;
        } catch (\Throwable $e) {
            $this->logger->warning('Bento thumbnails: store emulation failed; using original image URLs', [
                'error' => $e->getMessage(),
            ]);
        }

        try {
            foreach ($files as $key => $file) {
                $url = null;
                if ($emulating) {
                    try {
                        $url = $this->urlBuilder->getUrl($file, $this->imageId) ?: null;
                    } catch (\Throwable $e) {
                        $this->logger->warning('Bento thumbnail URL failed; using original image URL', [
                            'file' => $file,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
                $out[$key] = $url ?? $this->originalUrl($file, $storeId);
            }
        } finally {
            if ($emulating) {
                $this->emulation->stopEnvironmentEmulation();
            }
        }

        return $out;
    }

    private function originalUrl(string $file, int $storeId): ?string
    {
        try {
            $base = (string)$this->storeManager->getStore($storeId)->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
        } catch (\Throwable) {
            return null;
        }

        return $base === '' ? null : rtrim($base, '/') . '/catalog/product/' . ltrim($file, '/');
    }
}
