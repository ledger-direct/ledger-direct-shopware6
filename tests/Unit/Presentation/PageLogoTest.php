<?php declare(strict_types=1);

namespace Hardcastle\LedgerDirect\Tests\Unit\Presentation;

use Hardcastle\LedgerDirect\Presentation\PageLogo;
use Hardcastle\LedgerDirect\Service\ConfigurationService;
use Hardcastle\LedgerDirect\Tests\Mock\LedgerDirect\Service\ConfigurationServiceMock;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;

/**
 * Which logo the header shows: the theme's, a picture from the media
 * library, or the monogram — and the monogram whenever the picture is gone.
 */
class PageLogoTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const MEDIA_ID = '0189b3e4c5d64f0a9b6c1d2e3f4a5b6c';

    private Context $context;

    protected function setUp(): void
    {
        $this->context = new Context(new SystemSource());
    }

    public function testTheShopLogoIsTheDefaultAndNeedsNoLookup(): void
    {
        $media = Mockery::mock(EntityRepository::class);
        $media->shouldReceive('search')->never();

        $logo = $this->pageLogo([], $media)->forShop('Beispielshop', $this->context);

        $this->assertSame(['mode' => 'shop', 'url' => null, 'monogram' => 'B'], $logo);
    }

    public function testAnUnknownModeReadsAsTheShopLogo(): void
    {
        $logo = $this->pageLogo(['LedgerDirect.config.paymentPageLogoMode' => 'whatever'], Mockery::mock(EntityRepository::class))
            ->forShop('Shop', $this->context);

        $this->assertSame('shop', $logo['mode']);
    }

    public function testACustomLogoIsResolvedFromTheMediaLibrary(): void
    {
        $entity = new MediaEntity();
        $entity->setId(self::MEDIA_ID);
        $entity->setUrl('https://shop.example/media/logo.png');

        $logo = $this->pageLogo([
            'LedgerDirect.config.paymentPageLogoMode' => 'custom',
            'LedgerDirect.config.paymentPageLogo' => self::MEDIA_ID,
        ], $this->mediaRepositoryReturning($entity))->forShop('Shop', $this->context);

        $this->assertSame('custom', $logo['mode']);
        $this->assertSame('https://shop.example/media/logo.png', $logo['url']);
    }

    /**
     * The merchant chose a picture and later deleted it from the media
     * library: the monogram takes over, no broken image.
     */
    public function testAMissingMediaFallsBackToTheMonogram(): void
    {
        $logo = $this->pageLogo([
            'LedgerDirect.config.paymentPageLogoMode' => 'custom',
            'LedgerDirect.config.paymentPageLogo' => self::MEDIA_ID,
        ], $this->mediaRepositoryReturning(null))->forShop('Shop', $this->context);

        $this->assertSame(['mode' => 'none', 'url' => null, 'monogram' => 'S'], $logo);
    }

    public function testCustomWithoutAChosenMediaFallsBackToTheMonogram(): void
    {
        $media = Mockery::mock(EntityRepository::class);
        $media->shouldReceive('search')->never();

        $logo = $this->pageLogo(['LedgerDirect.config.paymentPageLogoMode' => 'custom'], $media)->forShop('Shop', $this->context);

        $this->assertSame('none', $logo['mode']);
    }

    public function testTheMonogramIsTheFirstLetterUpperCasedMultibyteSafe(): void
    {
        $this->assertSame('Ä', PageLogo::monogram('ärzte ohne grenzen'));
        $this->assertSame('B', PageLogo::monogram('  Beispielshop'));
        $this->assertSame('·', PageLogo::monogram('   '));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function pageLogo(array $config, EntityRepository $mediaRepository): PageLogo
    {
        return new PageLogo(ConfigurationServiceMock::createInstance($config), $mediaRepository);
    }

    private function mediaRepositoryReturning(?MediaEntity $entity): EntityRepository
    {
        $collection = Mockery::mock(EntityCollection::class);
        $collection->shouldReceive('first')->andReturn($entity);

        $result = Mockery::mock(EntitySearchResult::class);
        $result->shouldReceive('getEntities')->andReturn($collection);

        $repository = Mockery::mock(EntityRepository::class);
        $repository->shouldReceive('search')->once()->andReturn($result);

        return $repository;
    }
}
