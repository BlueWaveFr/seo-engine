<?php

namespace SeoExpert\Engine\Service\AI;

use SeoExpert\Engine\Entity\Project;

interface AIProviderInterface
{
    /**
     * Generate content ideas based on project context
     */
    public function generateContentIdeas(Project $project, array $options = []): array;

    /**
     * Generate a full article or content piece
     * @return array{title: string, meta_description: string, content_html: string}
     */
    public function generateContent(string $title, string $type, Project $project, array $options = []): array;

    /**
     * Analyze keywords and suggest improvements
     */
    public function analyzeKeywords(array $keywords, Project $project): array;

    /**
     * Generate editorial calendar suggestions
     */
    public function generateEditorialCalendar(Project $project, int $weeks = 4): array;

    /**
     * Optimize existing content for SEO
     */
    public function optimizeContent(string $content, string $targetKeyword, Project $project, string $contentType = 'article'): array;

    /**
     * Generate content ideas for a specific keyword
     */
    public function generateKeywordContentIdeas(Project $project, string $keyword, array $options = []): array;
}
