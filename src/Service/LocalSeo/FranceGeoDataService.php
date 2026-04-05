<?php

namespace SeoExpert\Engine\Service\LocalSeo;

/**
 * Service providing French geographic data for Local SEO
 *
 * Includes:
 * - 18 regions (2016 reform)
 * - 101 departments
 * - DataForSeo location codes for major cities
 */
class FranceGeoDataService
{
    // French regions (2016 reform) with their departments
    private const REGIONS = [
        'IDF' => ['name' => 'Ile-de-France', 'departments' => ['75', '77', '78', '91', '92', '93', '94', '95']],
        'ARA' => ['name' => 'Auvergne-Rhone-Alpes', 'departments' => ['01', '03', '07', '15', '26', '38', '42', '43', '63', '69', '73', '74']],
        'BFC' => ['name' => 'Bourgogne-Franche-Comte', 'departments' => ['21', '25', '39', '58', '70', '71', '89', '90']],
        'BRE' => ['name' => 'Bretagne', 'departments' => ['22', '29', '35', '56']],
        'CVL' => ['name' => 'Centre-Val de Loire', 'departments' => ['18', '28', '36', '37', '41', '45']],
        'COR' => ['name' => 'Corse', 'departments' => ['2A', '2B']],
        'GES' => ['name' => 'Grand Est', 'departments' => ['08', '10', '51', '52', '54', '55', '57', '67', '68', '88']],
        'HDF' => ['name' => 'Hauts-de-France', 'departments' => ['02', '59', '60', '62', '80']],
        'NOR' => ['name' => 'Normandie', 'departments' => ['14', '27', '50', '61', '76']],
        'NAQ' => ['name' => 'Nouvelle-Aquitaine', 'departments' => ['16', '17', '19', '23', '24', '33', '40', '47', '64', '79', '86', '87']],
        'OCC' => ['name' => 'Occitanie', 'departments' => ['09', '11', '12', '30', '31', '32', '34', '46', '48', '65', '66', '81', '82']],
        'PDL' => ['name' => 'Pays de la Loire', 'departments' => ['44', '49', '53', '72', '85']],
        'PAC' => ['name' => 'Provence-Alpes-Cote d\'Azur', 'departments' => ['04', '05', '06', '13', '83', '84']],
        // DOM-TOM
        'GUA' => ['name' => 'Guadeloupe', 'departments' => ['971']],
        'MTQ' => ['name' => 'Martinique', 'departments' => ['972']],
        'GUF' => ['name' => 'Guyane', 'departments' => ['973']],
        'REU' => ['name' => 'La Reunion', 'departments' => ['974']],
        'MYT' => ['name' => 'Mayotte', 'departments' => ['976']],
    ];

    // Department names
    private const DEPARTMENTS = [
        '01' => 'Ain',
        '02' => 'Aisne',
        '03' => 'Allier',
        '04' => 'Alpes-de-Haute-Provence',
        '05' => 'Hautes-Alpes',
        '06' => 'Alpes-Maritimes',
        '07' => 'Ardeche',
        '08' => 'Ardennes',
        '09' => 'Ariege',
        '10' => 'Aube',
        '11' => 'Aude',
        '12' => 'Aveyron',
        '13' => 'Bouches-du-Rhone',
        '14' => 'Calvados',
        '15' => 'Cantal',
        '16' => 'Charente',
        '17' => 'Charente-Maritime',
        '18' => 'Cher',
        '19' => 'Correze',
        '21' => 'Cote-d\'Or',
        '22' => 'Cotes-d\'Armor',
        '23' => 'Creuse',
        '24' => 'Dordogne',
        '25' => 'Doubs',
        '26' => 'Drome',
        '27' => 'Eure',
        '28' => 'Eure-et-Loir',
        '29' => 'Finistere',
        '2A' => 'Corse-du-Sud',
        '2B' => 'Haute-Corse',
        '30' => 'Gard',
        '31' => 'Haute-Garonne',
        '32' => 'Gers',
        '33' => 'Gironde',
        '34' => 'Herault',
        '35' => 'Ille-et-Vilaine',
        '36' => 'Indre',
        '37' => 'Indre-et-Loire',
        '38' => 'Isere',
        '39' => 'Jura',
        '40' => 'Landes',
        '41' => 'Loir-et-Cher',
        '42' => 'Loire',
        '43' => 'Haute-Loire',
        '44' => 'Loire-Atlantique',
        '45' => 'Loiret',
        '46' => 'Lot',
        '47' => 'Lot-et-Garonne',
        '48' => 'Lozere',
        '49' => 'Maine-et-Loire',
        '50' => 'Manche',
        '51' => 'Marne',
        '52' => 'Haute-Marne',
        '53' => 'Mayenne',
        '54' => 'Meurthe-et-Moselle',
        '55' => 'Meuse',
        '56' => 'Morbihan',
        '57' => 'Moselle',
        '58' => 'Nievre',
        '59' => 'Nord',
        '60' => 'Oise',
        '61' => 'Orne',
        '62' => 'Pas-de-Calais',
        '63' => 'Puy-de-Dome',
        '64' => 'Pyrenees-Atlantiques',
        '65' => 'Hautes-Pyrenees',
        '66' => 'Pyrenees-Orientales',
        '67' => 'Bas-Rhin',
        '68' => 'Haut-Rhin',
        '69' => 'Rhone',
        '70' => 'Haute-Saone',
        '71' => 'Saone-et-Loire',
        '72' => 'Sarthe',
        '73' => 'Savoie',
        '74' => 'Haute-Savoie',
        '75' => 'Paris',
        '76' => 'Seine-Maritime',
        '77' => 'Seine-et-Marne',
        '78' => 'Yvelines',
        '79' => 'Deux-Sevres',
        '80' => 'Somme',
        '81' => 'Tarn',
        '82' => 'Tarn-et-Garonne',
        '83' => 'Var',
        '84' => 'Vaucluse',
        '85' => 'Vendee',
        '86' => 'Vienne',
        '87' => 'Haute-Vienne',
        '88' => 'Vosges',
        '89' => 'Yonne',
        '90' => 'Territoire de Belfort',
        '91' => 'Essonne',
        '92' => 'Hauts-de-Seine',
        '93' => 'Seine-Saint-Denis',
        '94' => 'Val-de-Marne',
        '95' => 'Val-d\'Oise',
        '971' => 'Guadeloupe',
        '972' => 'Martinique',
        '973' => 'Guyane',
        '974' => 'La Reunion',
        '976' => 'Mayotte',
    ];

    // DataForSeo location codes for French cities
    private const DATAFORSEO_FRANCE_CITIES = [
        'paris' => '1006094',
        'marseille' => '1006273',
        'lyon' => '1006252',
        'toulouse' => '1006584',
        'nice' => '1006358',
        'nantes' => '1006334',
        'strasbourg' => '1006537',
        'montpellier' => '1006303',
        'bordeaux' => '1006069',
        'lille' => '1006234',
        'rennes' => '1006438',
        'reims' => '1006433',
        'saint-etienne' => '1006464',
        'toulon' => '1006579',
        'le havre' => '1006213',
        'grenoble' => '1006152',
        'dijon' => '1006114',
        'angers' => '1006025',
        'nimes' => '1006363',
        'villeurbanne' => '1006621',
        'le mans' => '1006219',
        'aix-en-provence' => '1006016',
        'clermont-ferrand' => '1006100',
        'brest' => '1006075',
        'tours' => '1006595',
        'limoges' => '1006238',
        'amiens' => '1006022',
        'annecy' => '1006027',
        'perpignan' => '1006404',
        'besancon' => '1006062',
        'metz' => '1006291',
        'orleans' => '1006387',
        'rouen' => '1006448',
        'mulhouse' => '1006323',
        'caen' => '1006080',
        'nancy' => '1006327',
        'saint-denis' => '1006468',
        'argenteuil' => '1006033',
        'montreuil' => '1006308',
        'roubaix' => '1006445',
    ];

    // Major cities with coordinates and population
    private const MAJOR_CITIES = [
        'paris' => ['lat' => 48.8566, 'lng' => 2.3522, 'population' => 2148000, 'department' => '75'],
        'marseille' => ['lat' => 43.2965, 'lng' => 5.3698, 'population' => 870000, 'department' => '13'],
        'lyon' => ['lat' => 45.7578, 'lng' => 4.8320, 'population' => 522000, 'department' => '69'],
        'toulouse' => ['lat' => 43.6047, 'lng' => 1.4442, 'population' => 493000, 'department' => '31'],
        'nice' => ['lat' => 43.7102, 'lng' => 7.2620, 'population' => 342000, 'department' => '06'],
        'nantes' => ['lat' => 47.2184, 'lng' => -1.5536, 'population' => 314000, 'department' => '44'],
        'strasbourg' => ['lat' => 48.5734, 'lng' => 7.7521, 'population' => 284000, 'department' => '67'],
        'montpellier' => ['lat' => 43.6108, 'lng' => 3.8767, 'population' => 290000, 'department' => '34'],
        'bordeaux' => ['lat' => 44.8378, 'lng' => -0.5792, 'population' => 260000, 'department' => '33'],
        'lille' => ['lat' => 50.6292, 'lng' => 3.0573, 'population' => 235000, 'department' => '59'],
        'rennes' => ['lat' => 48.1173, 'lng' => -1.6778, 'population' => 220000, 'department' => '35'],
        'reims' => ['lat' => 49.2583, 'lng' => 4.0317, 'population' => 182000, 'department' => '51'],
        'toulon' => ['lat' => 43.1242, 'lng' => 5.9280, 'population' => 175000, 'department' => '83'],
        'grenoble' => ['lat' => 45.1885, 'lng' => 5.7245, 'population' => 158000, 'department' => '38'],
        'dijon' => ['lat' => 47.3220, 'lng' => 5.0415, 'population' => 158000, 'department' => '21'],
        'angers' => ['lat' => 47.4784, 'lng' => -0.5632, 'population' => 155000, 'department' => '49'],
        'nimes' => ['lat' => 43.8367, 'lng' => 4.3601, 'population' => 151000, 'department' => '30'],
        'aix-en-provence' => ['lat' => 43.5297, 'lng' => 5.4474, 'population' => 147000, 'department' => '13'],
        'clermont-ferrand' => ['lat' => 45.7772, 'lng' => 3.0870, 'population' => 147000, 'department' => '63'],
        'brest' => ['lat' => 48.3904, 'lng' => -4.4861, 'population' => 139000, 'department' => '29'],
    ];

    /**
     * Enrich city data with French geographic information
     */
    public function enrichCityData(string $cityName, ?string $postalCode = null): array
    {
        $normalized = $this->normalizeLocationName($cityName);

        $data = [
            'name' => $cityName,
            'postalCode' => $postalCode,
            'departmentCode' => null,
            'regionCode' => null,
            'coordinates' => null,
            'population' => null,
            'dataforseoCode' => self::DATAFORSEO_FRANCE_CITIES[$normalized] ?? null,
        ];

        // Try to get data from major cities
        if (isset(self::MAJOR_CITIES[$normalized])) {
            $cityData = self::MAJOR_CITIES[$normalized];
            $data['coordinates'] = ['lat' => $cityData['lat'], 'lng' => $cityData['lng']];
            $data['population'] = $cityData['population'];
            $data['departmentCode'] = $cityData['department'];
            $data['regionCode'] = $this->getRegionFromDepartment($cityData['department']);
        }

        // Extract department from postal code if not already set
        if ($postalCode && !$data['departmentCode'] && strlen($postalCode) >= 2) {
            $deptCode = substr($postalCode, 0, 2);
            // Handle DOM-TOM (97x)
            if ($deptCode === '97' && strlen($postalCode) >= 3) {
                $deptCode = substr($postalCode, 0, 3);
            }
            // Handle Corsica (20 -> 2A or 2B)
            if ($deptCode === '20') {
                $deptCode = ((int) substr($postalCode, 2, 1) < 5) ? '2A' : '2B';
            }
            $data['departmentCode'] = $deptCode;
            $data['regionCode'] = $this->getRegionFromDepartment($deptCode);
        }

        return $data;
    }

    /**
     * Get all departments for a region
     */
    public function getDepartmentsByRegion(string $regionCode): array
    {
        $regionCode = strtoupper($regionCode);
        if (!isset(self::REGIONS[$regionCode])) {
            return [];
        }

        $result = [];
        foreach (self::REGIONS[$regionCode]['departments'] as $deptCode) {
            $result[] = [
                'code' => $deptCode,
                'name' => self::DEPARTMENTS[$deptCode] ?? $deptCode,
            ];
        }
        return $result;
    }

    /**
     * Get region from department code
     */
    public function getRegionFromDepartment(string $departmentCode): ?string
    {
        foreach (self::REGIONS as $code => $region) {
            if (in_array($departmentCode, $region['departments'])) {
                return $code;
            }
        }
        return null;
    }

    /**
     * Get region name from code
     */
    public function getRegionName(string $regionCode): ?string
    {
        $regionCode = strtoupper($regionCode);
        return self::REGIONS[$regionCode]['name'] ?? null;
    }

    /**
     * Get department name from code
     */
    public function getDepartmentName(string $departmentCode): ?string
    {
        return self::DEPARTMENTS[$departmentCode] ?? null;
    }

    /**
     * Get major cities for a department
     */
    public function getMajorCitiesByDepartment(string $departmentCode): array
    {
        $cities = [];
        foreach (self::MAJOR_CITIES as $city => $data) {
            if ($data['department'] === $departmentCode) {
                $cities[] = [
                    'name' => ucfirst($city),
                    'population' => $data['population'],
                    'coordinates' => ['lat' => $data['lat'], 'lng' => $data['lng']],
                ];
            }
        }

        // Sort by population descending
        usort($cities, fn($a, $b) => $b['population'] <=> $a['population']);

        return $cities;
    }

    /**
     * Get DataForSeo location code for a city
     */
    public function getDataforseoCode(string $cityName): ?string
    {
        $normalized = $this->normalizeLocationName($cityName);
        return self::DATAFORSEO_FRANCE_CITIES[$normalized] ?? null;
    }

    /**
     * Normalize location name for lookups
     */
    public function normalizeLocationName(string $name): string
    {
        $normalized = mb_strtolower($name);
        $normalized = str_replace(
            ['é', 'è', 'ê', 'ë', 'à', 'â', 'ô', 'î', 'ï', 'û', 'ù', 'ç', 'œ', 'æ'],
            ['e', 'e', 'e', 'e', 'a', 'a', 'o', 'i', 'i', 'u', 'u', 'c', 'oe', 'ae'],
            $normalized
        );
        return trim($normalized);
    }

    /**
     * Get all regions with their data
     */
    public function getAllRegions(): array
    {
        $result = [];
        foreach (self::REGIONS as $code => $data) {
            $result[$code] = [
                'code' => $code,
                'name' => $data['name'],
                'departmentCount' => count($data['departments']),
            ];
        }
        return $result;
    }

    /**
     * Get all departments
     */
    public function getAllDepartments(): array
    {
        $result = [];
        foreach (self::DEPARTMENTS as $code => $name) {
            $result[$code] = [
                'code' => $code,
                'name' => $name,
                'region' => $this->getRegionFromDepartment($code),
            ];
        }
        return $result;
    }

    /**
     * Search cities by name (partial match)
     */
    public function searchCities(string $query, int $limit = 10): array
    {
        $normalized = $this->normalizeLocationName($query);
        $results = [];

        foreach (self::MAJOR_CITIES as $city => $data) {
            if (str_contains($city, $normalized)) {
                $results[] = [
                    'name' => ucfirst(str_replace('-', ' ', $city)),
                    'department' => $data['department'],
                    'departmentName' => self::DEPARTMENTS[$data['department']] ?? null,
                    'region' => $this->getRegionFromDepartment($data['department']),
                    'population' => $data['population'],
                    'dataforseoCode' => self::DATAFORSEO_FRANCE_CITIES[$city] ?? null,
                ];
            }
        }

        // Sort by population descending
        usort($results, fn($a, $b) => $b['population'] <=> $a['population']);

        return array_slice($results, 0, $limit);
    }
}
