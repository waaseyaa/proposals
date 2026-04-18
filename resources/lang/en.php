<?php

declare(strict_types=1);

/*
 * Miikana i18n dictionary — English.
 *
 * Key format: flat dot-notation (`'domain.subkey' => 'Value'`) matching Minoo.
 * Consumption: `{{ trans('domain.subkey') }}` inside a Twig template, once
 * `Waaseyaa\I18n\Twig\TranslationTwigExtension` is registered and
 * `TranslatorInterface` + `LanguageManagerInterface` are bound in
 * `AppServiceProvider::register()`.
 *
 * Status: scaffold only. Dashboard page template (`pages/dashboard/index.html.twig`)
 * still hardcodes strings to guarantee byte-equivalent output during the
 * Twig-extraction refactor. Subsequent extraction passes should:
 *   1. Wire TranslatorInterface + LanguageManagerInterface in AppServiceProvider.
 *   2. Register TranslationTwigExtension on the shared Twig environment in boot().
 *   3. Replace hardcoded strings in templates with trans('...') calls keyed here.
 */

return [
    // Dashboard — hero card
    'dashboard.hero.eyebrow' => 'NorthOps on Waaseyaa',
    'dashboard.hero.title' => 'Miikana',
    'dashboard.hero.lede' => 'The first real product shell is live. This app starts from a schema-first proposal domain, not a generic form runner, and it already carries Bimaaji for graph introspection and sovereignty-aware mutation guardrails from the start.',

    // Dashboard — stat labels
    'dashboard.stat.submissions_label' => 'Submission workspaces live',
    'dashboard.stat.cohorts_label' => 'Cohorts tracked',
    'dashboard.stat.appendices_label' => 'ISET appendices targeted',
    'dashboard.stat.exports_label' => 'Exports currently on disk',

    // Dashboard — primary action links
    'dashboard.links.submissions' => 'Open submissions surface',
    'dashboard.links.cohorts' => 'Open cohort board',
    'dashboard.links.api' => 'Inspect API surface',

    // Dashboard — sidebar cards
    'dashboard.current_build.title' => 'Current Build',
    'dashboard.current_build.item1' => 'Proposal entities registered',
    'dashboard.current_build.item2' => 'NorthOps sovereignty profile active',
    'dashboard.current_build.item3' => 'Bimaaji graph available for app introspection',
    'dashboard.current_build.item4' => 'Review, exports, and cohort surfaces in place',

    'dashboard.next_code.title' => 'Next Code',
    'dashboard.next_code.item1' => 'Cohort dashboard for multi-participant operations',
    'dashboard.next_code.item2' => 'Deterministic readiness checks against generated artifacts',
    'dashboard.next_code.item3' => 'Bounded AI provider behind intake orchestration',
    'dashboard.next_code.item4' => 'More than one seeded participant submission',

    // Dashboard — architecture spine
    'dashboard.spine.title' => 'Architecture Spine',
    'dashboard.spine.item1_label' => 'Pipeline definitions',
    'dashboard.spine.item1_body' => 'hold reusable form maps and workflow metadata.',
    'dashboard.spine.item2_label' => 'Submissions',
    'dashboard.spine.item2_body' => 'hold canonical data, transcript state, validation, and unresolved items.',
    'dashboard.spine.item3_label' => 'Documents',
    'dashboard.spine.item3_body' => 'capture deterministic generated artifacts from canonical state.',
    'dashboard.spine.item4_label' => 'Reviews',
    'dashboard.spine.item4_body' => 'track staff comments and approval flow.',
    'dashboard.spine.item5_label' => 'Bimaaji',
    'dashboard.spine.item5_body' => 'exposes app graph and sovereignty context as machine-readable structure.',

    // Dashboard — graph panel
    'dashboard.graph.title' => 'Bimaaji Graph Snapshot',

    // Base layout — defaults
    'base.title' => 'Miikana',
];
