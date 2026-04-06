<?php

namespace SeoExpert\Engine\Service\Eeat;

use Dompdf\Dompdf;
use Dompdf\Options;
use SeoExpert\Engine\Entity\EeatSnapshot;

/**
 * Generates a professional PDF report from an EeatSnapshot.
 */
class EeatPdfReportService
{
    public function generate(EeatSnapshot $snapshot, ?array $comparisonData = null): string
    {
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'Helvetica');

        $dompdf = new Dompdf($options);
        $html = $this->buildHtml($snapshot, $comparisonData);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    private function buildHtml(EeatSnapshot $snapshot, ?array $comparisonData): string
    {
        $s = $snapshot;
        $date = $s->getCreatedAt()->format('d/m/Y H:i');
        $url = htmlspecialchars($s->getUrl());

        // Scores
        $eeat = $s->getEeatScore();
        $citability = $s->getAiCitabilityScore();
        $exp = $s->getExperienceScore();
        $expt = $s->getExpertiseScore();
        $auth = $s->getAuthorityScore();
        $trust = $s->getTrustScore();

        // Colors
        $eeatColor = $this->scoreColor($eeat);
        $citColor = $this->scoreColor($citability);
        $eeatLevel = $this->levelLabel($s->getEeatLevel());
        $citLevel = $this->levelLabel($s->getCitabilityLevel());

        // Signal details
        $signalDetails = $s->getSignalDetails();
        $citBreakdown = $s->getCitabilityBreakdown();
        $recommendations = $s->getRecommendations();
        $trustSignals = $s->getTrustSignals();
        $authors = $s->getAuthors();
        $freshness = $this->freshnessLabel($s->getContentFreshness());

        // Build sections
        $signalsHtml = $this->buildSignalsSection($signalDetails);
        $citBreakdownHtml = $this->buildCitabilitySection($citBreakdown);
        $recsHtml = $this->buildRecommendationsSection($recommendations);
        $comparisonHtml = $comparisonData ? $this->buildComparisonSection($comparisonData) : '';

        $pillarBars = '';
        foreach ([
            ['Experience', $exp, '#3B82F6'],
            ['Expertise', $expt, '#8B5CF6'],
            ['Autorite', $auth, '#F97316'],
            ['Confiance', $trust, '#22C55E'],
        ] as [$label, $score, $color]) {
            $pct = round(($score / 25) * 100);
            $pillarBars .= <<<HTML
            <div style="margin-bottom: 12px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                    <span style="font-size: 12px; color: #374151; font-weight: 600;">{$label}</span>
                    <span style="font-size: 12px; font-weight: 700; color: {$color};">{$score}/25</span>
                </div>
                <div style="height: 10px; background: #E5E7EB; border-radius: 5px; overflow: hidden;">
                    <div style="height: 100%; width: {$pct}%; background: {$color}; border-radius: 5px;"></div>
                </div>
            </div>
            HTML;
        }

        $authorsHtml = '';
        if (!empty($authors)) {
            $authorsHtml = '<div style="margin-top: 8px;">';
            foreach (array_slice($authors, 0, 5) as $author) {
                $name = htmlspecialchars($author['name'] ?? '');
                $source = $author['source'] === 'schema' ? 'Schema.org' : 'HTML';
                $authorsHtml .= "<span style=\"display: inline-block; padding: 3px 10px; margin: 2px 4px 2px 0; background: #EEF2FF; color: #4338CA; border-radius: 12px; font-size: 11px;\">{$name} ({$source})</span>";
            }
            $authorsHtml .= '</div>';
        }

        $signalsCount = count($trustSignals);
        $authorsCount = count($authors);
        $pagesCrawled = $s->getPagesCrawled();

        return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: Helvetica, Arial, sans-serif; color: #1F2937; font-size: 11px; line-height: 1.5; }
    .page { page-break-after: always; padding: 30px 35px; }
    .page:last-child { page-break-after: auto; }

    /* Header */
    .header { background: linear-gradient(135deg, #4338CA, #6D28D9); padding: 25px 30px; border-radius: 10px; color: white; margin-bottom: 25px; }
    .header h1 { font-size: 20px; font-weight: 700; margin-bottom: 4px; }
    .header .subtitle { font-size: 12px; opacity: 0.85; }
    .header .meta { margin-top: 12px; font-size: 10px; opacity: 0.75; }

    /* Cards */
    .card { border: 1px solid #E5E7EB; border-radius: 8px; padding: 18px; margin-bottom: 16px; background: #fff; }
    .card-title { font-size: 13px; font-weight: 700; color: #1F2937; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 2px solid #EEF2FF; }

    /* Score boxes */
    .score-row { display: flex; gap: 16px; margin-bottom: 20px; }
    .score-box { flex: 1; text-align: center; padding: 20px 15px; border-radius: 10px; border: 2px solid; }
    .score-box .value { font-size: 38px; font-weight: 800; line-height: 1.1; }
    .score-box .label { font-size: 11px; color: #6B7280; margin-top: 2px; }
    .score-box .level { font-size: 10px; font-weight: 600; margin-top: 6px; padding: 2px 10px; border-radius: 10px; display: inline-block; }

    /* Stats row */
    .stats-row { display: flex; gap: 10px; margin-bottom: 20px; }
    .stat { flex: 1; text-align: center; padding: 12px 8px; background: #F9FAFB; border-radius: 8px; border: 1px solid #E5E7EB; }
    .stat .num { font-size: 18px; font-weight: 700; color: #111827; }
    .stat .lbl { font-size: 9px; color: #6B7280; margin-top: 2px; }

    /* Tables */
    table { width: 100%; border-collapse: collapse; font-size: 10px; }
    th { background: #F3F4F6; padding: 6px 8px; text-align: left; font-weight: 600; color: #374151; border-bottom: 2px solid #E5E7EB; }
    td { padding: 5px 8px; border-bottom: 1px solid #F3F4F6; }
    .found { color: #059669; font-weight: 700; }
    .missing { color: #DC2626; }
    .partial { color: #D97706; }

    /* Recommendations */
    .rec { padding: 10px 14px; margin-bottom: 8px; border-radius: 8px; border-left: 4px solid; }
    .rec-critical { background: #FEF2F2; border-color: #DC2626; }
    .rec-high { background: #FFF7ED; border-color: #F97316; }
    .rec-medium { background: #FFFBEB; border-color: #EAB308; }
    .rec-low { background: #F9FAFB; border-color: #9CA3AF; }
    .rec .rec-title { font-weight: 700; font-size: 11px; color: #1F2937; }
    .rec .rec-desc { font-size: 10px; color: #4B5563; margin-top: 3px; }
    .rec .rec-badge { display: inline-block; font-size: 9px; font-weight: 600; padding: 1px 8px; border-radius: 8px; margin-left: 6px; }

    /* Pillar header */
    .pillar-header { font-size: 12px; font-weight: 700; padding: 8px 10px; border-radius: 6px; margin-bottom: 2px; color: #fff; }

    /* Comparison */
    .compare-bar { display: flex; gap: 6px; margin-bottom: 8px; }
    .compare-bar .bar-wrap { flex: 1; }
    .compare-bar .bar-label { font-size: 9px; color: #6B7280; margin-bottom: 2px; }
    .compare-bar .bar-bg { height: 8px; background: #E5E7EB; border-radius: 4px; overflow: hidden; }
    .compare-bar .bar-fill { height: 100%; border-radius: 4px; }

    /* Footer */
    .footer { text-align: center; font-size: 9px; color: #9CA3AF; margin-top: 20px; padding-top: 12px; border-top: 1px solid #E5E7EB; }
</style>
</head>
<body>

<!-- PAGE 1: Synthese -->
<div class="page">
    <div class="header">
        <h1>Rapport Audit E-E-A-T & Citabilite IA</h1>
        <div class="subtitle">Analyse complete des signaux de credibilite et de citabilite par les moteurs IA</div>
        <div class="meta">
            URL analysee : {$url} &nbsp;|&nbsp; Date : {$date} &nbsp;|&nbsp; Pages analysees : {$pagesCrawled}
        </div>
    </div>

    <!-- Score principal -->
    <div class="score-row">
        <div class="score-box" style="border-color: {$eeatColor}15; background: {$eeatColor}08;">
            <div style="font-size: 10px; font-weight: 600; color: #6B7280; margin-bottom: 4px;">SCORE E-E-A-T</div>
            <div class="value" style="color: {$eeatColor};">{$eeat}</div>
            <div class="label">/100</div>
            <div class="level" style="background: {$eeatColor}15; color: {$eeatColor};">{$eeatLevel}</div>
        </div>
        <div class="score-box" style="border-color: {$citColor}15; background: {$citColor}08;">
            <div style="font-size: 10px; font-weight: 600; color: #6B7280; margin-bottom: 4px;">CITABILITE IA</div>
            <div class="value" style="color: {$citColor};">{$citability}</div>
            <div class="label">/100</div>
            <div class="level" style="background: {$citColor}15; color: {$citColor};">{$citLevel}</div>
        </div>
    </div>

    <!-- Stats rapides -->
    <div class="stats-row">
        <div class="stat"><div class="num">{$signalsCount}</div><div class="lbl">Signaux de confiance</div></div>
        <div class="stat"><div class="num">{$authorsCount}</div><div class="lbl">Auteurs detectes</div></div>
        <div class="stat"><div class="num">{$freshness}</div><div class="lbl">Fraicheur contenu</div></div>
        <div class="stat"><div class="num">{$pagesCrawled}</div><div class="lbl">Pages analysees</div></div>
    </div>

    <!-- EEAT Breakdown -->
    <div class="card">
        <div class="card-title">Repartition E-E-A-T par pilier</div>
        {$pillarBars}
    </div>

    <!-- Auteurs -->
    {$authorsHtml}

    <!-- Citabilite IA -->
    <div class="card">
        <div class="card-title">Score de Citabilite IA — Dimensions</div>
        {$citBreakdownHtml}
    </div>

    <div class="footer">Rapport genere par WaveRank — waverank.io — {$date}</div>
</div>

<!-- PAGE 2: Signaux detailles -->
<div class="page">
    <div style="font-size: 16px; font-weight: 700; color: #4338CA; margin-bottom: 16px;">Signaux E-E-A-T — Detail des 28 criteres</div>
    {$signalsHtml}
    <div class="footer">Rapport genere par WaveRank — waverank.io — {$date}</div>
</div>

<!-- PAGE 3: Recommandations -->
<div class="page">
    <div style="font-size: 16px; font-weight: 700; color: #4338CA; margin-bottom: 16px;">Recommandations prioritaires</div>
    <p style="font-size: 11px; color: #6B7280; margin-bottom: 16px;">
        Actions classees par priorite pour ameliorer votre score E-E-A-T et votre citabilite dans les reponses IA (ChatGPT, Claude, Gemini, Google AI Overview).
    </p>
    {$recsHtml}

    {$comparisonHtml}

    <div class="footer">Rapport genere par WaveRank — waverank.io — {$date}</div>
</div>

</body>
</html>
HTML;
    }

    // ─── Section builders ────────────────────────────────────────────────

    private function buildCitabilitySection(array $breakdown): string
    {
        $html = '';
        foreach ($breakdown as $key => $item) {
            $label = $item['label'] ?? $key;
            $score = $item['score'] ?? 0;
            $max = $item['max'] ?? 1;
            $pct = round(($score / max($max, 1)) * 100);
            $color = $this->scoreColor(($score / max($max, 1)) * 100);

            $html .= <<<HTML
            <div style="margin-bottom: 10px;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 3px;">
                    <span style="font-size: 11px; color: #374151;">{$label}</span>
                    <span style="font-size: 11px; font-weight: 700; color: {$color};">{$score}/{$max}</span>
                </div>
                <div style="height: 8px; background: #E5E7EB; border-radius: 4px; overflow: hidden;">
                    <div style="height: 100%; width: {$pct}%; background: {$color}; border-radius: 4px;"></div>
                </div>
            </div>
            HTML;
        }
        return $html;
    }

    private function buildSignalsSection(array $signals): string
    {
        $pillars = [
            'experience' => ['label' => 'Experience', 'color' => '#3B82F6'],
            'expertise' => ['label' => 'Expertise', 'color' => '#8B5CF6'],
            'authoritativeness' => ['label' => 'Autorite', 'color' => '#F97316'],
            'trust' => ['label' => 'Confiance', 'color' => '#22C55E'],
        ];

        $html = '';
        foreach ($pillars as $pillar => $meta) {
            $pillarSignals = array_filter($signals, fn($s) => ($s['pillar'] ?? '') === $pillar);
            if (empty($pillarSignals)) continue;

            $found = count(array_filter($pillarSignals, fn($s) => $s['found'] ?? false));
            $total = count($pillarSignals);
            $pts = array_sum(array_column($pillarSignals, 'points'));
            $maxPts = array_sum(array_column($pillarSignals, 'max_points'));

            $html .= "<div class=\"pillar-header\" style=\"background: {$meta['color']};\">{$meta['label']} — {$found}/{$total} actifs ({$pts}/{$maxPts} pts)</div>";
            $html .= '<table><thead><tr><th style="width: 28px;"></th><th>Signal</th><th style="width: 65px; text-align: right;">Points</th></tr></thead><tbody>';

            foreach ($pillarSignals as $signal) {
                $icon = ($signal['found'] ?? false) ? '<span class="found">&#10003;</span>' :
                    (($signal['partial'] ?? false) ? '<span class="partial">~</span>' : '<span class="missing">&#10007;</span>');
                $cls = ($signal['found'] ?? false) ? 'found' : (($signal['partial'] ?? false) ? 'partial' : 'missing');
                $label = htmlspecialchars($signal['label'] ?? '');
                $points = ($signal['points'] ?? 0) . '/' . ($signal['max_points'] ?? 0);

                $html .= "<tr><td>{$icon}</td><td class=\"{$cls}\">{$label}</td><td style=\"text-align: right;\" class=\"{$cls}\">{$points}</td></tr>";
            }

            $html .= '</tbody></table><div style="margin-bottom: 14px;"></div>';
        }

        return $html;
    }

    private function buildRecommendationsSection(array $recommendations): string
    {
        $html = '';
        foreach (array_slice($recommendations, 0, 10) as $i => $rec) {
            $priority = $rec['priority'] ?? 'medium';
            $cls = "rec rec-{$priority}";
            $title = htmlspecialchars($rec['title'] ?? '');
            $desc = htmlspecialchars($rec['description'] ?? '');
            $impact = $rec['impact'] ?? '';

            $badgeColors = [
                'high' => 'background: #DCFCE7; color: #166534;',
                'medium' => 'background: #FEF3C7; color: #92400E;',
                'low' => 'background: #F3F4F6; color: #6B7280;',
            ];
            $badgeStyle = $badgeColors[$impact] ?? $badgeColors['medium'];
            $impactLabel = match ($impact) { 'high' => 'Impact fort', 'medium' => 'Impact moyen', default => 'Impact faible' };

            $num = $i + 1;
            $html .= "<div class=\"{$cls}\">";
            $html .= "<div class=\"rec-title\">{$num}. {$title}";
            if ($impact) {
                $html .= " <span class=\"rec-badge\" style=\"{$badgeStyle}\">{$impactLabel}</span>";
            }
            $html .= '</div>';
            if ($desc) {
                $html .= "<div class=\"rec-desc\">{$desc}</div>";
            }
            $html .= '</div>';
        }

        return $html;
    }

    private function buildComparisonSection(array $data): string
    {
        $projectUrl = htmlspecialchars($data['project']['url'] ?? '');
        $competitorUrl = htmlspecialchars($data['competitor']['url'] ?? '');

        $html = '<div style="margin-top: 30px;">';
        $html .= '<div style="font-size: 16px; font-weight: 700; color: #4338CA; margin-bottom: 16px;">Benchmark concurrent</div>';
        $html .= "<p style=\"font-size: 10px; color: #6B7280; margin-bottom: 14px;\">Comparaison entre <strong>{$projectUrl}</strong> et <strong>{$competitorUrl}</strong></p>";

        // Score comparison
        $html .= '<div class="card"><div class="card-title">Comparaison des scores</div>';

        $labels = [
            'eeat_score' => ['E-E-A-T Global', 100],
            'ai_citability_score' => ['Citabilite IA', 100],
            'experience_score' => ['Experience', 25],
            'expertise_score' => ['Expertise', 25],
            'authority_score' => ['Autorite', 25],
            'trust_score' => ['Confiance', 25],
        ];

        foreach ($data['comparison'] ?? [] as $key => $vals) {
            [$label, $max] = $labels[$key] ?? [$key, 100];
            $pProject = (int) $vals['project'];
            $pComp = (int) $vals['competitor'];
            $pctP = round(($pProject / max($max, 1)) * 100);
            $pctC = round(($pComp / max($max, 1)) * 100);
            $diff = $pProject - $pComp;
            $diffColor = $diff > 0 ? '#059669' : ($diff < 0 ? '#DC2626' : '#6B7280');
            $diffSign = $diff > 0 ? '+' : '';

            $html .= <<<HTML
            <div style="margin-bottom: 10px;">
                <div style="display: flex; justify-content: space-between; font-size: 11px; margin-bottom: 3px;">
                    <span style="font-weight: 600;">{$label}</span>
                    <span style="font-weight: 700; color: {$diffColor};">{$diffSign}{$diff}</span>
                </div>
                <div style="display: flex; gap: 8px;">
                    <div style="flex: 1;">
                        <div style="font-size: 9px; color: #6B7280;">Votre site: {$pProject}/{$max}</div>
                        <div style="height: 6px; background: #E5E7EB; border-radius: 3px; overflow: hidden;">
                            <div style="height: 100%; width: {$pctP}%; background: #4338CA; border-radius: 3px;"></div>
                        </div>
                    </div>
                    <div style="flex: 1;">
                        <div style="font-size: 9px; color: #6B7280;">Concurrent: {$pComp}/{$max}</div>
                        <div style="height: 6px; background: #E5E7EB; border-radius: 3px; overflow: hidden;">
                            <div style="height: 100%; width: {$pctC}%; background: #9CA3AF; border-radius: 3px;"></div>
                        </div>
                    </div>
                </div>
            </div>
            HTML;
        }

        $html .= '</div>';

        // Missing signals
        $missing = $data['missing_signals'] ?? [];
        $advantages = $data['advantage_signals'] ?? [];

        if ($missing || $advantages) {
            $html .= '<div style="display: flex; gap: 12px; margin-top: 14px;">';

            if ($missing) {
                $html .= '<div class="card" style="flex: 1; border-color: #FECACA;">';
                $html .= '<div class="card-title" style="color: #DC2626; border-color: #FECACA;">Signaux manquants vs concurrent</div>';
                foreach ($missing as $sig) {
                    $sigLabel = str_replace('_', ' ', $sig);
                    $html .= "<div style=\"padding: 4px 8px; margin-bottom: 4px; background: #FEF2F2; border-radius: 4px; font-size: 10px; color: #991B1B;\">&#10007; {$sigLabel}</div>";
                }
                $html .= '</div>';
            }

            if ($advantages) {
                $html .= '<div class="card" style="flex: 1; border-color: #BBF7D0;">';
                $html .= '<div class="card-title" style="color: #059669; border-color: #BBF7D0;">Vos avantages</div>';
                foreach ($advantages as $sig) {
                    $sigLabel = str_replace('_', ' ', $sig);
                    $html .= "<div style=\"padding: 4px 8px; margin-bottom: 4px; background: #F0FDF4; border-radius: 4px; font-size: 10px; color: #166534;\">&#10003; {$sigLabel}</div>";
                }
                $html .= '</div>';
            }

            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function scoreColor(int $score): string
    {
        if ($score >= 80) return '#059669';
        if ($score >= 60) return '#D97706';
        if ($score >= 40) return '#EA580C';
        return '#DC2626';
    }

    private function levelLabel(string $level): string
    {
        return match ($level) {
            'excellent' => 'Excellent',
            'good' => 'Bon',
            'fair' => 'Moyen',
            'weak' => 'Faible',
            'critical' => 'Critique',
            'highly_citable' => 'Tres citable',
            'citable' => 'Citable',
            'partially_citable' => 'Partiellement citable',
            'low_citability' => 'Peu citable',
            'not_citable' => 'Non citable',
            default => $level,
        };
    }

    private function freshnessLabel(string $freshness): string
    {
        return match ($freshness) {
            'fresh' => 'Frais',
            'recent' => 'Recent',
            'stale' => 'Ancien',
            default => 'Inconnu',
        };
    }
}
