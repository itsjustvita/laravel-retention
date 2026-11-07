<!doctype html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<title>{{ $text->text('title') }}</title>
<style>
    body { font-family: "DejaVu Sans", "Helvetica Neue", Arial, sans-serif; font-size: 11pt; line-height: 1.45; color: #1a1a1a; max-width: 62rem; margin: 2rem auto; padding: 0 1rem; }
    h1 { font-size: 22pt; margin-bottom: .2rem; }
    h2 { font-size: 15pt; margin-top: 2rem; border-bottom: 1px solid #999; padding-bottom: .2rem; }
    h3 { font-size: 12pt; margin-top: 1.4rem; }
    table { border-collapse: collapse; width: 100%; font-size: 9.5pt; margin: .6rem 0; }
    th, td { border: 1px solid #999; padding: .3rem .45rem; text-align: left; vertical-align: top; }
    th { background: #eee; }
    .cover { page-break-after: always; }
    .cover table { width: auto; font-size: 11pt; }
    .subtitle { color: #555; margin-top: 0; }
    @media print { body { margin: 0; max-width: none; } h2, h3 { page-break-after: avoid; } tr { page-break-inside: avoid; } }
</style>
</head>
<body>

<section class="cover">
<h1>{{ $text->text('title') }}</h1>
<p class="subtitle">{{ $text->text('subtitle') }}</p>
<table>
<tr><th>{{ $text->text('cover.organisation') }}</th><td>{{ $concept->organisation ?? $text->text('not_set') }}</td></tr>
<tr><th>{{ $text->text('cover.controller') }}</th><td>{{ $concept->controller ?? $text->text('not_set') }}</td></tr>
<tr><th>{{ $text->text('cover.dpo') }}</th><td>{{ $concept->dataProtectionOfficer ?? $text->text('not_set') }}</td></tr>
<tr><th>{{ $text->text('cover.version') }}</th><td>{{ $concept->version ?? $text->text('not_set') }}</td></tr>
<tr><th>{{ $text->text('cover.as_of') }}</th><td>{{ $text->date($concept->asOf) }}</td></tr>
@if ($concept->commit !== null)
<tr><th>{{ $text->text('cover.commit') }}</th><td>{{ $concept->commit }}</td></tr>
@endif
</table>
</section>

<h2>{{ $text->text('purpose.heading') }}</h2>
<p>{{ $text->configured($concept->purpose, 'purpose.text') }}</p>

<h2>{{ $text->text('categories.heading') }}</h2>
<table>
<thead>
<tr>
<th>{{ $text->text('categories.category') }}</th>
<th>{{ $text->text('categories.targets') }}</th>
<th>{{ $text->text('categories.deletion_class') }}</th>
<th>{{ $text->text('categories.rule') }}</th>
<th>{{ $text->text('categories.legal_basis') }}</th>
<th>{{ $text->text('categories.action') }}</th>
<th>{{ $text->text('categories.erasure') }}</th>
<th>{{ $text->text('categories.responsible') }}</th>
</tr>
</thead>
<tbody>
@foreach ($concept->entries as $entry)
<tr>
<td>{{ $entry->label }}</td>
<td>{{ implode(', ', $entry->targets) }}</td>
<td>{{ $text->period($entry->rule->period) }}, {{ $text->text('start_events.'.$entry->startEvent->value) }}{{ $entry->rule->counting->value === 'end_of_year' ? '*' : '' }}</td>
<td>{{ $text->rule($entry->rule, 'label') }}</td>
<td>{{ $text->rule($entry->rule, 'legal_basis') }}</td>
<td>{{ $text->text('actions.'.$entry->action->value) }}</td>
<td>{{ $entry->rule->blocksErasure ? __('retention::concept.categories.blocking', ['ground' => $text->rule($entry->rule, 'erasure_ground')]) : $text->text('categories.not_blocking') }}</td>
<td>{{ $entry->responsible }}</td>
</tr>
@endforeach
</tbody>
</table>

<h2>{{ $text->text('matrix.heading') }}</h2>
<p>{{ $text->text('matrix.intro') }}</p>
<table>
<thead>
<tr>
<th>{{ $text->text('matrix.period') }}</th>
<th>{{ $text->text('start_events.collection') }}</th>
<th>{{ $text->text('start_events.end_of_transaction') }}</th>
<th>{{ $text->text('start_events.end_of_relationship') }}</th>
</tr>
</thead>
<tbody>
@foreach ($concept->matrix() as $row)
<tr>
<td>{{ $text->period($row['period']) }}</td>
<td>{{ $text->matrixCell($row['cells']['collection']) }}</td>
<td>{{ $text->matrixCell($row['cells']['end_of_transaction']) }}</td>
<td>{{ $text->matrixCell($row['cells']['end_of_relationship']) }}</td>
</tr>
@endforeach
</tbody>
</table>
@if ($concept->usesEndOfYearCounting())
<p>{{ $text->text('matrix.end_of_year') }}</p>
@endif

<h2>{{ $text->text('details.heading') }}</h2>
@foreach ($concept->entries as $entry)
<h3>{{ $entry->label }}</h3>
@if ($entry->description !== null)
<p>{{ $entry->description }}</p>
@endif
<ul>
<li><strong>{{ $text->text('details.targets') }}:</strong> {{ implode(', ', $entry->targets) }}</li>
<li><strong>{{ $text->text('details.rule') }}:</strong> {{ $text->rule($entry->rule, 'label') }} ({{ $text->text('types.'.$entry->rule->type->value) }})</li>
<li><strong>{{ $text->text('details.period') }}:</strong> {{ $text->period($entry->rule->period) }}</li>
<li><strong>{{ $text->text('details.start_event') }}:</strong> {{ $text->text('start_events.'.$entry->startEvent->value) }}</li>
<li><strong>{{ $text->text('details.counting') }}:</strong> {{ $text->text('counting.'.$entry->rule->counting->value) }}</li>
<li><strong>{{ $text->text('details.legal_basis') }}:</strong> {{ $text->rule($entry->rule, 'legal_basis') }}</li>
<li><strong>{{ $text->text('details.action') }}:</strong> {{ $text->text('actions.'.$entry->action->value) }}</li>
<li><strong>{{ $text->text('details.erasure') }}:</strong> {{ $entry->rule->blocksErasure ? __('retention::concept.categories.blocking', ['ground' => $text->rule($entry->rule, 'erasure_ground')]) : $text->text('categories.not_blocking') }}</li>
<li><strong>{{ $text->text('details.responsible') }}:</strong> {{ $entry->responsible }}</li>
@foreach ($text->previousVersions($entry->rule) as $version)
<li><strong>{{ $text->text('details.previous') }}:</strong> {{ __('retention::concept.details.previous_version', ['date' => $version['until'], 'period' => $version['period']]) }}</li>
@endforeach
@if ($entry->blockedBy !== [])
<li>{{ __('retention::concept.details.blocked_by', ['relations' => implode(', ', $entry->blockedBy)]) }}</li>
@endif
@if ($entry->inheritsHoldsFrom !== [])
<li>{{ __('retention::concept.details.inherits_holds', ['relations' => implode(', ', $entry->inheritsHoldsFrom)]) }}</li>
@endif
@if ($text->rule($entry->rule, 'note') !== null)
<li><strong>{{ $text->text('details.note') }}:</strong> {{ $text->rule($entry->rule, 'note') }}</li>
@endif
</ul>
@endforeach

<h2>{{ $text->text('implementation.heading') }}</h2>
<h3>{{ $text->text('implementation.deletion_heading') }}</h3>
<p>{{ $text->text('implementation.deletion') }}</p>
<h3>{{ $text->text('implementation.anonymisation_heading') }}</h3>
<p>{{ $text->text('implementation.anonymisation') }}</p>
<h3>{{ $text->text('implementation.restriction_heading') }}</h3>
<p>{{ $text->text('implementation.restriction') }}</p>
<h3>{{ $text->text('implementation.erasure_heading') }}</h3>
<p>{{ $text->text('implementation.erasure') }}</p>
<h3>{{ $text->text('implementation.schedule_heading') }}</h3>
<p>{{ __('retention::concept.implementation.schedule', ['timezone' => $concept->timezone]) }}</p>
<h3>{{ $text->text('implementation.log_heading') }}</h3>
<p>{{ $concept->logEnabled ? __('retention::concept.implementation.log', ['period' => $text->period($concept->logRetention)]) : $text->text('implementation.log_disabled') }}</p>
<h3>{{ $text->text('implementation.hold_heading') }}</h3>
<p>{{ __('retention::concept.implementation.hold', ['period' => $text->period($concept->holdsRetention)]) }}</p>

<h2>{{ $text->text('exclusions.heading') }}</h2>
@if ($concept->excludedModels === [] && $concept->excludedTables === [])
<p>{{ $text->text('exclusions.none') }}</p>
@else
<p>{{ $text->text('exclusions.intro') }}</p>
<table>
<thead>
<tr><th>{{ $text->text('exclusions.name') }}</th><th>{{ $text->text('exclusions.justification') }}</th></tr>
</thead>
<tbody>
@foreach ([...$concept->excludedModels, ...$concept->excludedTables] as $name => $justification)
<tr><td>{{ $name }}</td><td>{{ $justification }}</td></tr>
@endforeach
</tbody>
</table>
@endif

<h2>{{ $text->text('systems.heading') }}</h2>
<p>{{ $text->configured($concept->systemsNotCovered, 'systems.text') }}</p>

</body>
</html>
