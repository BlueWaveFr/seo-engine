<?php

namespace SeoExpert\Engine\Service\Crawler;

use SeoExpert\Engine\Entity\ApiKey;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class KeywordAnalyzerService
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const MODEL = 'claude-sonnet-4-20250514';
    private const MAX_TOKENS = 16384; // Increased for complete semantic cocoon analysis

    private ?string $effectiveApiKey = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $entityManager,
        private readonly string $claudeApiKey = '',
    ) {
        $this->initializeApiKey();
    }

    private function initializeApiKey(): void
    {
        // Try to get API key from database first
        $apiKeyEntity = $this->entityManager->getRepository(ApiKey::class)
            ->findOneBy(['provider' => ApiKey::PROVIDER_ANTHROPIC, 'isActive' => true]);

        if ($apiKeyEntity && $apiKeyEntity->getApiKey()) {
            $this->effectiveApiKey = $apiKeyEntity->getApiKey();
        } elseif (!empty($this->claudeApiKey)) {
            $this->effectiveApiKey = $this->claudeApiKey;
        }
    }

    private function getApiKey(): string
    {
        if (!$this->effectiveApiKey) {
            throw new \RuntimeException('Anthropic API key not configured');
        }
        return $this->effectiveApiKey;
    }

    /**
     * Analyze crawled website content to extract keywords and semantic insights
     */
    public function analyzeWebsiteContent(array $crawledData, array $projectContext = []): array
    {
        // Prepare content summary for analysis
        $contentSummary = $this->prepareContentSummary($crawledData);

        // Build the analysis prompt
        $prompt = $this->buildAnalysisPrompt($contentSummary, $projectContext);

        // Call Claude API
        $response = $this->callClaudeApi($prompt);

        return $this->parseAnalysisResponse($response);
    }

    /**
     * Prepare a condensed summary of crawled content for AI analysis
     */
    private function prepareContentSummary(array $crawledData): array
    {
        $summary = [
            'domain' => $crawledData['domain'] ?? '',
            'pagesCount' => $crawledData['statistics']['totalPages'] ?? 0,
            'totalWords' => $crawledData['statistics']['totalWords'] ?? 0,
            'titles' => [],
            'headings' => [],
            'metaDescriptions' => [],
            'textSamples' => [],
        ];

        // Collect unique titles
        if (!empty($crawledData['globalContent']['allTitles'])) {
            $summary['titles'] = array_unique($crawledData['globalContent']['allTitles']);
        }

        // Collect and group headings
        if (!empty($crawledData['globalContent']['allHeadings'])) {
            $h1s = [];
            $h2s = [];
            foreach ($crawledData['globalContent']['allHeadings'] as $heading) {
                if ($heading['level'] === 1) {
                    $h1s[] = $heading['text'];
                } elseif ($heading['level'] === 2) {
                    $h2s[] = $heading['text'];
                }
            }
            $summary['headings'] = [
                'h1' => array_unique($h1s),
                'h2' => array_slice(array_unique($h2s), 0, 50), // Increased from 30 for better cocoon analysis
            ];
        }

        // Collect meta descriptions
        if (!empty($crawledData['globalContent']['allMetaDescriptions'])) {
            $summary['metaDescriptions'] = array_slice(
                array_unique($crawledData['globalContent']['allMetaDescriptions']),
                0,
                20
            );
        }

        // Get text samples (first 500 chars of each page)
        if (!empty($crawledData['globalContent']['allTextContent'])) {
            foreach ($crawledData['globalContent']['allTextContent'] as $text) {
                if (strlen($text) > 100) {
                    $summary['textSamples'][] = mb_substr($text, 0, 500);
                }
            }
            $summary['textSamples'] = array_slice($summary['textSamples'], 0, 20); // Increased from 10 for better analysis
        }

        // Add page URLs with titles for accurate cocoon mapping
        $summary['pagesList'] = [];
        if (!empty($crawledData['pages'])) {
            foreach ($crawledData['pages'] as $page) {
                $summary['pagesList'][] = [
                    'url' => $page['url'] ?? '',
                    'title' => $page['title'] ?? '',
                ];
            }
        }

        return $summary;
    }

    /**
     * Build the analysis prompt for Claude
     */
    private function buildAnalysisPrompt(array $contentSummary, array $projectContext): string
    {
        $titlesStr = implode("\n- ", $contentSummary['titles']);
        $h1sStr = implode("\n- ", $contentSummary['headings']['h1'] ?? []);
        $h2sStr = implode("\n- ", $contentSummary['headings']['h2'] ?? []);
        $metaStr = implode("\n- ", $contentSummary['metaDescriptions']);
        $textsStr = implode("\n\n---\n\n", $contentSummary['textSamples']);

        // Build pages list for accurate cocoon mapping
        $pagesListStr = '';
        if (!empty($contentSummary['pagesList'])) {
            $pageLines = [];
            foreach ($contentSummary['pagesList'] as $page) {
                $pageLines[] = "- {$page['title']} ({$page['url']})";
            }
            $pagesListStr = implode("\n", $pageLines);
        }

        $contextStr = '';
        if (!empty($projectContext)) {
            $contextStr = "Contexte additionnel du projet:\n";
            if (!empty($projectContext['industry'])) {
                $contextStr .= "- Industrie: {$projectContext['industry']}\n";
            }
            if (!empty($projectContext['description'])) {
                $contextStr .= "- Description: {$projectContext['description']}\n";
            }
            if (!empty($projectContext['targetCountry'])) {
                $contextStr .= "- Pays cible: {$projectContext['targetCountry']}\n";
            }
            if (!empty($projectContext['targetLanguage'])) {
                $contextStr .= "- Langue: {$projectContext['targetLanguage']}\n";
            }
        }

        return <<<PROMPT
Tu es un expert SEO specialise dans l'analyse semantique de sites web. Analyse le contenu suivant extrait du site "{$contentSummary['domain']}" et fournis une analyse complete des mots-cles et opportunites SEO.

DONNEES DU SITE:
- Nombre de pages analysees: {$contentSummary['pagesCount']}
- Nombre total de mots: {$contentSummary['totalWords']}

LISTE COMPLETE DES PAGES DU SITE (utilise ces donnees pour remplir existingContent dans les cocons):
{$pagesListStr}

TITRES DES PAGES:
- {$titlesStr}

TITRES H1:
- {$h1sStr}

TITRES H2 (echantillon):
- {$h2sStr}

META DESCRIPTIONS:
- {$metaStr}

EXTRAITS DE CONTENU:
{$textsStr}

{$contextStr}

ANALYSE DEMANDEE:
1. Identifie les THEMES PRINCIPAUX du site (3-5 themes) et structure-les en COCON SEMANTIQUE
2. Extrait les MOTS-CLES PRINCIPAUX actuellement utilises (10-15 mots-cles)
3. Identifie les MOTS-CLES SECONDAIRES et longue traine (15-20)
4. Detecte les OPPORTUNITES DE CONTENU manquantes avec des recommandations DETAILLEES sur le TYPE de contenu, le FORMAT et la POSITION dans le cocon
5. Analyse la COHERENCE SEMANTIQUE globale
6. Propose des RECOMMANDATIONS pour ameliorer le SEO semantique

IMPORTANT pour le cocon semantique:
- Utilise la LISTE COMPLETE DES PAGES pour identifier les contenus existants
- Assigne TOUTES les pages existantes aux themes/pilliers pertinents (ne pas oublier de pages)
- Pour chaque pillier, liste TOUS les contenus existants qui correspondent au theme

Reponds UNIQUEMENT en JSON avec ce format exact:
{
  "themes": [
    {
      "name": "Nom du theme",
      "description": "Description courte",
      "coverage": "high|medium|low",
      "relatedKeywords": ["kw1", "kw2"],
      "isPillar": true,
      "suggestedPillarContent": "Titre de la page pilier suggere"
    }
  ],
  "primaryKeywords": [
    {
      "keyword": "mot-cle",
      "frequency": "high|medium|low",
      "searchIntent": "informational|transactional|navigational|commercial",
      "optimizationScore": 8,
      "suggestedImprovements": "Suggestion d'amelioration"
    }
  ],
  "secondaryKeywords": [
    {
      "keyword": "mot-cle longue traine",
      "parentTheme": "Nom du theme parent",
      "potential": "high|medium|low"
    }
  ],
  "contentOpportunities": [
    {
      "topic": "Sujet manquant",
      "rationale": "Pourquoi ce sujet est important pour le SEO et l'autorite thematique",
      "suggestedTitle": "Titre suggere optimise SEO",
      "targetKeywords": ["kw1", "kw2"],
      "priority": "high|medium|low",
      "contentType": "guide|article|pillar_page|faq|glossary|comparison|case_study|tutorial",
      "contentFormat": "long_form|how_to|listicle|comparison|step_by_step|qa|definition|interview",
      "coconPosition": "pillar|support_level_1|support_level_2",
      "parentTheme": "Nom du theme parent pour le cocon",
      "linkedTo": ["Titres des contenus auxquels lier"],
      "estimatedWordCount": 2000,
      "searchVolumePotential": "high|medium|low",
      "competitionLevel": "high|medium|low",
      "quickWin": false
    }
  ],
  "semanticCocoon": {
    "pillars": [
      {
        "theme": "Nom du theme pilier",
        "existingContent": ["Titre de page 1 (URL)", "Titre de page 2 (URL)"],
        "missingContent": ["Contenus de support manquants a creer"],
        "coverageScore": 60,
        "interlinkingScore": 40
      }
    ],
    "recommendations": [
      {
        "action": "Creer une page pilier sur X",
        "impact": "high|medium|low",
        "effort": "high|medium|low"
      }
    ]
  },
  "semanticAnalysis": {
    "coherenceScore": 75,
    "strengths": ["Point fort 1", "Point fort 2"],
    "weaknesses": ["Point faible 1", "Point faible 2"],
    "topicalAuthority": "high|medium|low",
    "contentGaps": ["Gap 1", "Gap 2"],
    "contentDepthScore": 60,
    "internalLinkingScore": 50
  },
  "recommendations": [
    {
      "category": "content|structure|keywords|internal_linking|semantic_cocoon",
      "priority": "high|medium|low",
      "action": "Action recommandee",
      "expectedImpact": "Impact attendu",
      "relatedOpportunity": "Titre de l'opportunite de contenu liee si applicable"
    }
  ],
  "summary": {
    "overallScore": 70,
    "mainStrength": "Principal point fort du site",
    "mainWeakness": "Principal point a ameliorer",
    "quickWins": ["Action rapide 1", "Action rapide 2", "Action rapide 3"],
    "priorityContentToCreate": "Le premier contenu a creer en priorite avec son type"
  }
}
PROMPT;
    }

    /**
     * Call Claude API for analysis
     */
    /**
     * Get the year to use in content references.
     * Before November → current year. From November → next year.
     */
    private function getContentYear(): string
    {
        $now = new \DateTime();
        $currentMonth = (int) $now->format('n');
        $currentYear = (int) $now->format('Y');

        if ($currentMonth >= 11) {
            return (string) ($currentYear + 1);
        }

        return (string) $currentYear;
    }

    private function callClaudeApi(string $prompt): string
    {
        $currentDate = (new \DateTime())->format('d/m/Y');
        $contentYear = $this->getContentYear();

        $systemPrompt = <<<SYSTEM
Tu es un expert SEO francophone specialise dans l'analyse semantique et l'optimisation de contenu pour les moteurs de recherche. Tu analyses les sites web de maniere approfondie pour identifier les opportunites d'amelioration du referencement naturel.

DATE ACTUELLE: {$currentDate}
ANNÉE DE RÉFÉRENCE: {$contentYear}

Regles:
- Reponds toujours en francais
- Fournis des analyses actionables et concretes
- Base tes recommandations sur les bonnes pratiques SEO actuelles de {$contentYear}
- Sois precis dans l'identification des mots-cles et themes
- Priorise les recommandations par impact potentiel
- Reponds uniquement avec du JSON valide et parsable

REGLES DE TEMPORALITE CRITIQUES:
- L'annee de reference pour les titres et contenus est {$contentYear}
- Ne JAMAIS mentionner 2024 ou des annees passees comme si c'etait le present ou le futur
- Pour les titres contenant une annee, utilise TOUJOURS {$contentYear}
- Les recommandations doivent etre orientees vers {$contentYear}

FIABILITÉ DES DONNÉES:
- N'invente AUCUNE statistique, chiffre ou pourcentage
- N'invente AUCUNE étude, rapport, institut ou source
- Privilégie les affirmations factuelles générales et vérifiables
- Si un chiffre est incertain, ajoute [À VÉRIFIER]
SYSTEM;

        try {
            $response = $this->httpClient->request('POST', self::API_URL, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-api-key' => $this->getApiKey(),
                    'anthropic-version' => '2023-06-01',
                ],
                'timeout' => 180, // 3 minutes timeout for complex analysis
                'json' => [
                    'model' => self::MODEL,
                    'max_tokens' => self::MAX_TOKENS,
                    'system' => $systemPrompt,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt],
                    ],
                ],
            ]);

            $data = $response->toArray();
            return $data['content'][0]['text'] ?? '';

        } catch (\Exception $e) {
            $this->logger->error('Claude API error during keyword analysis: ' . $e->getMessage());
            throw new \RuntimeException('Failed to analyze keywords: ' . $e->getMessage());
        }
    }

    /**
     * Parse the analysis response from Claude
     */
    private function parseAnalysisResponse(string $response): array
    {
        // Extract JSON from response
        if (preg_match('/\{[\s\S]*\}/', $response, $matches)) {
            $jsonStr = $matches[0];
        } else {
            $jsonStr = $response;
        }

        try {
            $data = json_decode($jsonStr, true, 512, JSON_THROW_ON_ERROR);
            return $data;
        } catch (\JsonException $e) {
            $this->logger->warning('Failed to parse keyword analysis JSON: ' . $e->getMessage());
            return [
                'error' => 'Failed to parse analysis',
                'raw_response' => $response,
            ];
        }
    }

    /**
     * Enrich analysis with Google Search Console data
     */
    public function enrichWithSearchConsoleData(array $analysis, array $gscData): array
    {
        $enrichedAnalysis = $analysis;

        // Create a map of GSC queries for quick lookup
        $gscQueries = [];
        if (!empty($gscData['queries']['rows'])) {
            foreach ($gscData['queries']['rows'] as $row) {
                $query = strtolower($row['query'] ?? '');
                if ($query) {
                    $gscQueries[$query] = [
                        'clicks' => $row['clicks'] ?? 0,
                        'impressions' => $row['impressions'] ?? 0,
                        'ctr' => $row['ctr'] ?? 0,
                        'position' => $row['position'] ?? 0,
                    ];
                }
            }
        }

        // Enrich primary keywords with real search data
        if (!empty($enrichedAnalysis['primaryKeywords'])) {
            foreach ($enrichedAnalysis['primaryKeywords'] as &$kw) {
                $keyword = strtolower($kw['keyword']);
                if (isset($gscQueries[$keyword])) {
                    $kw['gscData'] = $gscQueries[$keyword];
                    $kw['hasRealData'] = true;
                } else {
                    // Try partial match
                    foreach ($gscQueries as $query => $data) {
                        if (str_contains($query, $keyword) || str_contains($keyword, $query)) {
                            $kw['gscData'] = $data;
                            $kw['gscMatchedQuery'] = $query;
                            $kw['hasRealData'] = true;
                            break;
                        }
                    }
                }
            }
        }

        // Enrich secondary keywords
        if (!empty($enrichedAnalysis['secondaryKeywords'])) {
            foreach ($enrichedAnalysis['secondaryKeywords'] as &$kw) {
                $keyword = strtolower($kw['keyword']);
                foreach ($gscQueries as $query => $data) {
                    if (str_contains($query, $keyword) || str_contains($keyword, $query)) {
                        $kw['gscData'] = $data;
                        $kw['gscMatchedQuery'] = $query;
                        $kw['hasRealData'] = true;
                        break;
                    }
                }
            }
        }

        // Add top performing queries from GSC that might be missing from analysis
        $discoveredKeywords = [];
        foreach ($gscQueries as $query => $data) {
            // High performing queries (good impressions or clicks)
            if ($data['impressions'] >= 100 || $data['clicks'] >= 5) {
                $alreadyIncluded = false;

                // Check if already in primary keywords
                foreach ($enrichedAnalysis['primaryKeywords'] ?? [] as $kw) {
                    if (strtolower($kw['keyword']) === $query) {
                        $alreadyIncluded = true;
                        break;
                    }
                }

                if (!$alreadyIncluded) {
                    $discoveredKeywords[] = [
                        'keyword' => $query,
                        'source' => 'gsc_discovery',
                        'gscData' => $data,
                        'potential' => $data['impressions'] >= 500 ? 'high' : ($data['impressions'] >= 100 ? 'medium' : 'low'),
                    ];
                }
            }
        }

        // Sort discovered keywords by impressions
        usort($discoveredKeywords, fn($a, $b) => $b['gscData']['impressions'] <=> $a['gscData']['impressions']);

        $enrichedAnalysis['discoveredFromGSC'] = array_slice($discoveredKeywords, 0, 20);

        // Add GSC summary
        $enrichedAnalysis['gscSummary'] = [
            'totalQueries' => count($gscQueries),
            'queriesWithClicks' => count(array_filter($gscQueries, fn($q) => $q['clicks'] > 0)),
            'topPerformer' => !empty($gscQueries) ? array_key_first($gscQueries) : null,
            'enrichedAt' => (new \DateTimeImmutable())->format('c'),
        ];

        return $enrichedAnalysis;
    }

    /**
     * Generate keyword suggestions based on analysis
     */
    public function generateKeywordSuggestions(array $analysis): array
    {
        $suggestions = [];

        // Extract from primary keywords
        if (!empty($analysis['primaryKeywords'])) {
            foreach ($analysis['primaryKeywords'] as $kw) {
                $suggestions[] = [
                    'keyword' => $kw['keyword'],
                    'type' => 'primary',
                    'score' => $kw['optimizationScore'] ?? 5,
                    'intent' => $kw['searchIntent'] ?? 'informational',
                ];
            }
        }

        // Extract from secondary keywords
        if (!empty($analysis['secondaryKeywords'])) {
            foreach ($analysis['secondaryKeywords'] as $kw) {
                $potentialScore = match($kw['potential'] ?? 'medium') {
                    'high' => 8,
                    'medium' => 5,
                    'low' => 3,
                    default => 5,
                };
                $suggestions[] = [
                    'keyword' => $kw['keyword'],
                    'type' => 'secondary',
                    'score' => $potentialScore,
                    'theme' => $kw['parentTheme'] ?? null,
                ];
            }
        }

        // Sort by score descending
        usort($suggestions, fn($a, $b) => $b['score'] <=> $a['score']);

        return $suggestions;
    }
}
