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

    // Dashboard — page/browser title (override of base.title on the dashboard route)
    'dashboard.page.title' => 'Miikana',

    // Base layout — defaults
    'base.title' => 'Miikana',

    // Intake — scaffold (template still hardcodes strings for byte-equivalence; keys live here
    // for the follow-up pass that switches the intake template to trans() calls). Exception:
    // 'intake.page.title' is used now in the <title> block override, since browser tabs are a
    // low-risk conversion that validates trans() works in block content.
    'intake.page.title' => 'Intake · Miikana',
    'intake.hero.eyebrow' => 'Conversational Intake',
    'intake.hero.lede' => 'This is the bounded intake loop: every turn is stored, structured patches are applied into canonical_data, and unresolved fields stay visible instead of disappearing into chat history.',
    'intake.hero.provider_label' => 'Provider mode:',
    'intake.nav.back' => 'Back to submissions',
    'intake.nav.structured' => 'Structured data',
    'intake.nav.documents' => 'Appendix documents',
    'intake.nav.review' => 'Review',
    'intake.notice.updated' => 'Intake turn stored and structured patches applied.',
    'intake.notice.error' => 'Unable to apply the intake turn. Check the message and try again.',
    'intake.transcript.title' => 'Transcript',
    'intake.transcript.empty' => 'No intake turns recorded yet.',
    'intake.research_log.title' => 'Research Log',
    'intake.research_log.empty' => 'No research artifacts captured yet.',
    'intake.research_log.citations' => 'Citations',
    'intake.research_log.default_citation_title' => 'Source',
    'intake.research_log.no_summary' => 'No summary available.',
    'intake.new_turn.title' => 'New Turn',
    'intake.new_turn.hint_prefix' => 'Use direct field lines like',
    'intake.new_turn.hint_example_1' => 'business.operations.launch_timeline: Within 10 days',
    'intake.new_turn.hint_or' => 'or short statements like',
    'intake.new_turn.hint_example_2' => 'customers include Band office, local families',
    'intake.new_turn.placeholder' => 'Describe the business, answer a follow-up question, or send direct field lines.',
    'intake.new_turn.submit' => 'Apply Intake Turn',
    'intake.next_question.title' => 'Next Question',
    'intake.next_question.priority_suffix' => 'priority',
    'intake.next_question.empty' => 'No queued next question. The current question plan is satisfied.',
    'intake.unresolved.title' => 'Unresolved Core Fields',
    'intake.unresolved.empty' => 'No unresolved core intake fields.',
    'intake.turn.patches_applied' => 'Applied:',
    'intake.turn.patch_confidence_label' => 'confidence',
    'intake.turn.patch_resolved' => 'resolved',
    'intake.turn.patch_unresolved' => 'follow-up needed',
    'intake.turn.no_timestamp' => 'n/a',
    'intake.turn.hint_title' => 'Follow-up hint',
    'intake.turn.hints_heading' => 'Provider Hints',
    'intake.turn.research_requests_heading' => 'Research Requests',
    'intake.turn.executed_heading' => 'Executed Research',
    'intake.turn.executed_suffix' => 'executed',
    'intake.turn.research_insights_heading' => 'Research Summary',
    'intake.turn.research_insight_title' => 'Research Insight',

    // Cohorts — page titles (two routes converted to Twig in prompt #6). Remaining
    // cohort-template strings stay hardcoded for byte-equivalence; a later dedicated
    // pass promotes them to trans() once a second surface re-uses the same labels.
    'cohorts.index.page.title' => 'Cohorts · Miikana',
    'cohorts.show.page.title' => 'Cohort Detail · Miikana',

    // Submissions — page titles. Index landed in prompt #7, show() in prompt #8.
    // Remaining strings stay hardcoded until a dedicated trans() pass.
    'submissions.index.page.title' => 'Submissions · Miikana',
    'submissions.show.page.title' => 'Submission Detail · Miikana',

    // Reviews — page title (review cockpit migrated in prompt #8).
    'reviews.show.page.title' => 'Review · Miikana',

    // Documents — page titles (three HTML routes migrated in prompt #8). Export
    // file-view and download routes stay non-Twig (raw file contents / binary).
    'documents.show.page.title' => 'Document Previews · Miikana',
    'documents.package.page.title' => 'Merged Package · Miikana',
    'documents.exports.page.title' => 'Exports · Miikana',
];
