<?php

namespace SeoExpert\Engine\Service\LocalSeo;

use SeoExpert\Engine\Entity\Content;
use SeoExpert\Engine\Entity\GeoZone;
use SeoExpert\Engine\Entity\Location;
use SeoExpert\Engine\Entity\Project;
use SeoExpert\Engine\Entity\User;
use SeoExpert\Engine\Service\AI\ClaudeService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Main service for Local SEO operations
 */
class LocalSeoService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClaudeService $claudeService,
        private readonly FranceGeoDataService $geoDataService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Import locations for a project
     */
    public function importLocations(Project $project, array $locationsData): array
    {
        $imported = [];
        foreach ($locationsData as $data) {
            $location = $this->createLocationFromData($project, $data);
            $this->em->persist($location);
            $imported[] = $location;
        }
        $this->em->flush();
        return $imported;
    }

    /**
     * Create a location from various input formats
     */
    public function createLocationFromData(Project $project, array $data): Location
    {
        $location = new Location();
        $location->setProject($project);
        $location->setName($data['name']);
        $location->setType($data['type'] ?? Location::TYPE_CITY);

        if (isset($data['postalCode'])) {
            $location->setPostalCode($data['postalCode']);
        }
        if (isset($data['isPrimary'])) {
            $location->setIsPrimary($data['isPrimary']);
        }

        // Auto-detect and enrich with French geo data
        if ($location->getType() === Location::TYPE_CITY) {
            $geoData = $this->geoDataService->enrichCityData(
                $data['name'],
                $data['postalCode'] ?? null
            );
            if ($geoData['departmentCode']) {
                $location->setDepartmentCode($geoData['departmentCode']);
            }
            if ($geoData['regionCode']) {
                $location->setRegionCode($geoData['regionCode']);
            }
            if ($geoData['coordinates']) {
                $location->setCoordinates($geoData['coordinates']);
            }
            if ($geoData['population']) {
                $location->setPopulation($geoData['population']);
            }
            if ($geoData['dataforseoCode']) {
                $location->setDataforseoLocationCode($geoData['dataforseoCode']);
            }
        } elseif ($location->getType() === Location::TYPE_DEPARTMENT) {
            // For departments, set the code and region
            $deptCode = $data['departmentCode'] ?? $this->extractDepartmentCode($data['name']);
            if ($deptCode) {
                $location->setDepartmentCode($deptCode);
                $location->setRegionCode($this->geoDataService->getRegionFromDepartment($deptCode));
            }
        } elseif ($location->getType() === Location::TYPE_REGION) {
            $location->setRegionCode($data['regionCode'] ?? null);
        }

        return $location;
    }

    /**
     * Generate local landing pages for all active locations
     */
    public function generateLocalLandingPages(Project $project, string $service, User $user): array
    {
        $generated = [];
        $locations = $project->getLocations();

        foreach ($locations as $location) {
            if (!$location->isActive()) {
                continue;
            }

            try {
                $content = $this->generateSingleLandingPage($project, $location, $service, $user);
                $generated[] = $content;
            } catch (\Exception $e) {
                $this->logger->error('Failed to generate landing page for location', [
                    'location' => $location->getName(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $generated;
    }

    /**
     * Generate a single local landing page
     */
    public function generateSingleLandingPage(
        Project $project,
        Location $location,
        string $service,
        User $user
    ): Content {
        // Build localized keyword
        $targetKeyword = $this->buildLocalKeyword($service, $location);

        // Get location-specific context
        $localContext = $this->getLocalContext($location);

        // Generate content via Claude
        $generatedData = $this->claudeService->generateLocalContent(
            $project,
            $location,
            $service,
            $targetKeyword,
            $localContext
        );

        // Create content entity
        $content = new Content();
        $content->setProject($project);
        $content->setCreatedBy($user);
        $content->setTitle($generatedData['title'] ?? $targetKeyword);
        $content->setBody($generatedData['content_html'] ?? '');
        $content->setMetaTitle($generatedData['meta_title'] ?? null);
        $content->setMetaDescription($generatedData['meta_description'] ?? null);
        $content->setType(Content::TYPE_LOCAL_LANDING);
        $content->setTargetKeyword($targetKeyword);
        $content->setLocation($location);
        $content->setIsLocationSpecific(true);
        $content->setStatus(Content::STATUS_DRAFT);

        // Generate LocalBusiness schema
        $schema = $this->buildLocalBusinessSchema($project, $location, $service);
        $content->setLocalSchemaMarkup($schema);

        $this->em->persist($content);
        $this->em->flush();

        return $content;
    }

    /**
     * Create a semantic cocoon for a geographic zone
     */
    public function createGeoCocoon(Project $project, GeoZone $zone, User $user): array
    {
        $result = [
            'hub' => null,
            'satellites' => [],
            'linking' => [],
        ];

        // 1. Generate hub page (pillar content) for the zone
        $hubContent = $this->generateZoneHubPage($project, $zone, $user);
        $zone->setHubContent($hubContent);
        $result['hub'] = $hubContent;

        // 2. Generate satellite pages for each location in the zone
        $satellites = [];
        $services = $project->getLocalServices() ?? [];
        $primaryService = $services[0] ?? 'service';

        foreach ($zone->getLocations() as $location) {
            if (!$location->isActive()) {
                continue;
            }

            try {
                $satelliteContent = $this->generateSingleLandingPage(
                    $project,
                    $location,
                    $primaryService,
                    $user
                );
                $satelliteContent->setGeoZone($zone);
                $satellites[] = $satelliteContent;
            } catch (\Exception $e) {
                $this->logger->error('Failed to generate satellite page', [
                    'location' => $location->getName(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
        $result['satellites'] = $satellites;

        // 3. Build internal linking structure
        $linkingStrategy = $this->buildCocoonLinking($hubContent, $satellites);
        $zone->setCocoonStrategy($linkingStrategy);
        $result['linking'] = $linkingStrategy;

        $this->em->flush();

        return $result;
    }

    /**
     * Analyze local keywords for a location
     */
    public function analyzeLocalKeywords(Location $location, array $services): array
    {
        $keywords = [];

        foreach ($services as $service) {
            $localKeyword = $this->buildLocalKeyword($service, $location);
            $variations = $this->generateLocalKeywordVariations($service, $location);

            $keywords[$service] = [
                'keyword' => $localKeyword,
                'variations' => $variations,
            ];
        }

        // Cache results on location
        $location->setLocalKeywords($keywords);
        $this->em->flush();

        return $keywords;
    }

    /**
     * Get local context for a location
     */
    public function getLocalContext(Location $location): array
    {
        $context = [
            'name' => $location->getName(),
            'type' => $location->getType(),
            'postalCode' => $location->getPostalCode(),
            'departmentCode' => $location->getDepartmentCode(),
            'departmentName' => $location->getDepartmentCode()
                ? $this->geoDataService->getDepartmentName($location->getDepartmentCode())
                : null,
            'regionCode' => $location->getRegionCode(),
            'regionName' => $location->getRegionCode()
                ? $this->geoDataService->getRegionName($location->getRegionCode())
                : null,
            'population' => $location->getPopulation(),
            'coordinates' => $location->getCoordinates(),
        ];

        // Add nearby cities if available
        if ($location->getDepartmentCode()) {
            $nearbyCities = $this->geoDataService->getMajorCitiesByDepartment($location->getDepartmentCode());
            // Exclude current city
            $context['nearbyCities'] = array_filter(
                $nearbyCities,
                fn($city) => $this->geoDataService->normalizeLocationName($city['name'])
                    !== $this->geoDataService->normalizeLocationName($location->getName())
            );
        }

        return $context;
    }

    /**
     * Build local keyword from service and location
     */
    public function buildLocalKeyword(string $service, Location $location): string
    {
        $locationName = strtolower($location->getName());
        return sprintf('%s %s', strtolower($service), $locationName);
    }

    /**
     * Generate keyword variations for local SEO
     */
    private function generateLocalKeywordVariations(string $service, Location $location): array
    {
        $name = strtolower($location->getName());
        $serviceLower = strtolower($service);
        $dept = $location->getDepartmentCode();

        $variations = [
            "{$serviceLower} {$name}",
            "{$serviceLower} a {$name}",
            "{$serviceLower} sur {$name}",
            "meilleur {$serviceLower} {$name}",
            "{$serviceLower} pas cher {$name}",
            "{$serviceLower} professionnel {$name}",
            "trouver {$serviceLower} {$name}",
        ];

        if ($dept) {
            $variations[] = "{$serviceLower} {$dept}";
            $deptName = $this->geoDataService->getDepartmentName($dept);
            if ($deptName) {
                $variations[] = "{$serviceLower} " . strtolower($deptName);
            }
        }

        if ($location->getPostalCode()) {
            $variations[] = "{$serviceLower} {$location->getPostalCode()}";
        }

        return $variations;
    }

    /**
     * Build LocalBusiness schema markup
     */
    private function buildLocalBusinessSchema(Project $project, Location $location, string $service): array
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type' => 'LocalBusiness',
            'name' => $project->getName(),
            'description' => $project->getDescription(),
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => $location->getName(),
                'addressCountry' => 'FR',
            ],
            'areaServed' => [
                '@type' => 'City',
                'name' => $location->getName(),
            ],
        ];

        if ($location->getPostalCode()) {
            $schema['address']['postalCode'] = $location->getPostalCode();
        }

        if ($location->getCoordinates()) {
            $schema['geo'] = [
                '@type' => 'GeoCoordinates',
                'latitude' => $location->getCoordinates()['lat'],
                'longitude' => $location->getCoordinates()['lng'],
            ];
        }

        // Add business info from project if available
        $businessInfo = $project->getLocalBusinessInfo();
        if ($businessInfo) {
            if (!empty($businessInfo['phone'])) {
                $schema['telephone'] = $businessInfo['phone'];
            }
            if (!empty($businessInfo['email'])) {
                $schema['email'] = $businessInfo['email'];
            }
            if (!empty($businessInfo['openingHours'])) {
                $schema['openingHours'] = $businessInfo['openingHours'];
            }
        }

        if ($project->getWebsiteUrl()) {
            $schema['url'] = $project->getWebsiteUrl();
        }

        return $schema;
    }

    /**
     * Generate hub page for a geographic zone
     */
    private function generateZoneHubPage(Project $project, GeoZone $zone, User $user): Content
    {
        $locations = $zone->getActiveLocations();
        $locationNames = array_map(fn($l) => $l->getName(), $locations->toArray());

        $services = $project->getLocalServices() ?? [];
        $primaryService = $services[0] ?? 'nos services';

        $title = sprintf('%s dans %s', ucfirst($primaryService), $zone->getName());

        // Generate hub content via Claude
        $generatedData = $this->claudeService->generateGeoZoneHub(
            $project,
            $zone,
            $locationNames,
            $primaryService
        );

        $content = new Content();
        $content->setProject($project);
        $content->setCreatedBy($user);
        $content->setTitle($generatedData['title'] ?? $title);
        $content->setBody($generatedData['content_html'] ?? '');
        $content->setMetaTitle($generatedData['meta_title'] ?? null);
        $content->setMetaDescription($generatedData['meta_description'] ?? null);
        $content->setType(Content::TYPE_PILLAR_PAGE);
        $content->setGeoZone($zone);
        $content->setIsLocationSpecific(true);
        $content->setStatus(Content::STATUS_DRAFT);

        $this->em->persist($content);

        return $content;
    }

    /**
     * Build cocoon linking strategy
     */
    private function buildCocoonLinking(Content $hub, array $satellites): array
    {
        $strategy = [
            'hubId' => $hub->getId()->toRfc4122(),
            'hubTitle' => $hub->getTitle(),
            'satelliteIds' => [],
            'links' => [],
        ];

        foreach ($satellites as $satellite) {
            $satelliteId = $satellite->getId()->toRfc4122();
            $strategy['satelliteIds'][] = $satelliteId;

            // Hub -> Satellite link
            $strategy['links'][] = [
                'from' => $hub->getId()->toRfc4122(),
                'to' => $satelliteId,
                'type' => 'hub_to_satellite',
                'anchorText' => $satellite->getTargetKeyword() ?? $satellite->getTitle(),
            ];

            // Satellite -> Hub link
            $strategy['links'][] = [
                'from' => $satelliteId,
                'to' => $hub->getId()->toRfc4122(),
                'type' => 'satellite_to_hub',
                'anchorText' => $hub->getTitle(),
            ];
        }

        // Add sibling links (satellite to satellite)
        for ($i = 0; $i < count($satellites); $i++) {
            $nextIndex = ($i + 1) % count($satellites);
            if ($nextIndex !== $i && count($satellites) > 1) {
                $strategy['links'][] = [
                    'from' => $satellites[$i]->getId()->toRfc4122(),
                    'to' => $satellites[$nextIndex]->getId()->toRfc4122(),
                    'type' => 'sibling',
                    'anchorText' => $satellites[$nextIndex]->getTitle(),
                ];
            }
        }

        return $strategy;
    }

    /**
     * Extract department code from name
     */
    private function extractDepartmentCode(string $name): ?string
    {
        // Try to match common patterns like "Seine-et-Marne (77)"
        if (preg_match('/\((\d{2,3})\)/', $name, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
