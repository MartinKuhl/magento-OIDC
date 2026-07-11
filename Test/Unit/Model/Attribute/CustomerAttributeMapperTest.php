<?php

declare(strict_types=1);

namespace M2Oidc\OAuth\Test\Unit\Model\Attribute;

use Magento\Directory\Model\Country;
use Magento\Directory\Model\ResourceModel\Country\Collection as CountryCollection;
use Magento\Directory\Model\ResourceModel\Country\CollectionFactory as CountryCollectionFactory;
use M2Oidc\OAuth\Helper\OAuthUtility;
use M2Oidc\OAuth\Model\Attribute\CountryResolver;
use M2Oidc\OAuth\Model\Attribute\CustomerAttributeMapper;
use M2Oidc\OAuth\Model\Attribute\GenderMapper;
use M2Oidc\OAuth\Model\Attribute\Transformer;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CustomerAttributeMapper (Phase 3.2).
 *
 * @covers \M2Oidc\OAuth\Model\Attribute\CustomerAttributeMapper
 */
class CustomerAttributeMapperTest extends TestCase
{
    /** @var OAuthUtility&MockObject */
    private OAuthUtility $oauthUtility;

    /** @var CountryCollectionFactory&MockObject */
    private CountryCollectionFactory $countryCollectionFactory;

    /** @var Transformer&MockObject */
    private Transformer $transformer;

    /** @var CustomerAttributeMapper */
    private CustomerAttributeMapper $mapper;

    protected function setUp(): void
    {
        $this->oauthUtility             = $this->createMock(OAuthUtility::class);
        $this->countryCollectionFactory = $this->createMock(CountryCollectionFactory::class);

        $this->oauthUtility->method('customlog');

        $this->transformer = $this->createMock(Transformer::class);

        $this->mapper = new CustomerAttributeMapper(
            $this->oauthUtility,
            $this->transformer,
            new GenderMapper(),
            new CountryResolver($this->countryCollectionFactory, $this->oauthUtility)
        );
    }

    // -------------------------------------------------------------------------
    // Date of birth
    // -------------------------------------------------------------------------

    public function testMapReturnsDobFormattedAsIso(): void
    {
        $result = $this->mapper->map(
            ['birthdate' => '1990/01/15'],
            ['dob' => 'birthdate']
        );

        $this->assertSame('1990-01-15', $result['dob']);
    }

    public function testMapReturnsDobFromIsoInput(): void
    {
        $result = $this->mapper->map(
            ['dob_claim' => '1985-06-30'],
            ['dob' => 'dob_claim']
        );

        $this->assertSame('1985-06-30', $result['dob']);
    }

    public function testMapOmitsDobKeyWhenClaimMissing(): void
    {
        $result = $this->mapper->map([], ['dob' => 'birthdate']);

        $this->assertArrayNotHasKey('dob', $result);
    }

    public function testMapOmitsDobKeyWhenClaimNameNotConfigured(): void
    {
        $result = $this->mapper->map(['birthdate' => '1990-01-01'], []);

        $this->assertArrayNotHasKey('dob', $result);
    }

    // -------------------------------------------------------------------------
    // Gender
    // -------------------------------------------------------------------------

    public function testMapReturnsMaleGenderId(): void
    {
        foreach (['male', 'Male', 'MALE', 'm', 'M', '1'] as $maleValue) {
            $result = $this->mapper->map(
                ['gender' => $maleValue],
                ['gender' => 'gender']
            );
            $this->assertSame(1, $result['gender'], "Expected gender ID 1 for value '{$maleValue}'");
        }
    }

    public function testMapReturnsFemaleGenderId(): void
    {
        foreach (['female', 'Female', 'f', 'F', '2'] as $femaleValue) {
            $result = $this->mapper->map(
                ['gender' => $femaleValue],
                ['gender' => 'gender']
            );
            $this->assertSame(2, $result['gender'], "Expected gender ID 2 for value '{$femaleValue}'");
        }
    }

    public function testMapReturnsNotSpecifiedGenderIdForUnknownValue(): void
    {
        $result = $this->mapper->map(
            ['gender' => 'non-binary'],
            ['gender' => 'gender']
        );

        $this->assertSame(3, $result['gender']);
    }

    public function testMapOmitsGenderKeyWhenClaimMissing(): void
    {
        $result = $this->mapper->map([], ['gender' => 'gender']);

        $this->assertArrayNotHasKey('gender', $result);
    }

    // -------------------------------------------------------------------------
    // Billing address fields
    // -------------------------------------------------------------------------

    public function testMapReturnsBillingStreet(): void
    {
        $result = $this->mapper->map(
            ['address' => '123 Main St'],
            ['billing_address' => 'address']
        );

        $this->assertSame('123 Main St', $result['billing_street']);
    }

    public function testMapReturnsBillingCity(): void
    {
        $result = $this->mapper->map(
            ['locality' => 'Portland'],
            ['billing_city' => 'locality']
        );

        $this->assertSame('Portland', $result['billing_city']);
    }

    public function testMapReturnsBillingPostcode(): void
    {
        $result = $this->mapper->map(
            ['postal_code' => '97201'],
            ['billing_zip' => 'postal_code']
        );

        $this->assertSame('97201', $result['billing_postcode']);
    }

    public function testMapReturnsBillingTelephone(): void
    {
        $result = $this->mapper->map(
            ['phone_number' => '+1-503-555-0100'],
            ['billing_phone' => 'phone_number']
        );

        $this->assertSame('+1-503-555-0100', $result['billing_telephone']);
    }

    // -------------------------------------------------------------------------
    // Country resolution
    // -------------------------------------------------------------------------

    public function testMapResolvesCountryIsoCodeAsUppercase(): void
    {
        // 2-letter ISO code — no collection lookup needed
        $this->countryCollectionFactory->expects($this->never())->method('create');

        $result = $this->mapper->map(
            ['country' => 'us'],
            ['billing_country' => 'country']
        );

        $this->assertSame('US', $result['billing_country_id']);
    }

    public function testMapResolvesCountryByFullName(): void
    {
        $countryMock = $this->createMock(Country::class);
        $countryMock->method('getName')->willReturn('United States');
        $countryMock->method('getId')->willReturn('US');

        $collection = $this->getMockBuilder(CountryCollection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getIterator'])
            ->getMock();
        $collection->method('getIterator')->willReturn(
            new \ArrayIterator([$countryMock])
        );

        $this->countryCollectionFactory->method('create')->willReturn($collection);

        $result = $this->mapper->map(
            ['country' => 'United States'],
            ['billing_country' => 'country']
        );

        $this->assertSame('US', $result['billing_country_id']);
    }

    public function testMapOmitsCountryKeyWhenClaimMissing(): void
    {
        $result = $this->mapper->map([], ['billing_country' => 'country']);

        $this->assertArrayNotHasKey('billing_country_id', $result);
    }

    // -------------------------------------------------------------------------
    // Dot-path traversal
    // -------------------------------------------------------------------------

    public function testMapExtractsDobViaRawAttrsDotPath(): void
    {
        $result = $this->mapper->map(
            [],
            [
                'dob'        => 'profile.birthdate',
                '_raw_attrs' => ['profile' => ['birthdate' => '2000-03-15']],
            ]
        );

        $this->assertSame('2000-03-15', $result['dob']);
    }

    // -------------------------------------------------------------------------
    // All-empty input produces empty result
    // -------------------------------------------------------------------------

    public function testMapOmitsAllKeysWhenNoClaimsPresent(): void
    {
        $result = $this->mapper->map([], [
            'dob'             => 'birthdate',
            'gender'          => 'gender',
            'billing_address' => 'address',
            'billing_city'    => 'locality',
            'billing_zip'     => 'postal_code',
            'billing_country' => 'country',
            'billing_phone'   => 'phone_number',
        ]);

        $this->assertSame([], $result);
    }
}
