# {!! $text->text('title') !!}

{!! $text->text('subtitle') !!}

| | |
|---|---|
| {!! $text->text('cover.organisation') !!} | {!! $text->cell($concept->organisation ?? $text->text('not_set')) !!} |
| {!! $text->text('cover.controller') !!} | {!! $text->cell($concept->controller ?? $text->text('not_set')) !!} |
| {!! $text->text('cover.dpo') !!} | {!! $text->cell($concept->dataProtectionOfficer ?? $text->text('not_set')) !!} |
| {!! $text->text('cover.version') !!} | {!! $text->cell($concept->version ?? $text->text('not_set')) !!} |
| {!! $text->text('cover.as_of') !!} | {!! $text->date($concept->asOf) !!} |
@if ($concept->commit !== null)
| {!! $text->text('cover.commit') !!} | {!! $concept->commit !!} |
@endif

## {!! $text->text('purpose.heading') !!}

{!! $text->configured($concept->purpose, 'purpose.text') !!}

## {!! $text->text('categories.heading') !!}

| {!! $text->text('categories.category') !!} | {!! $text->text('categories.targets') !!} | {!! $text->text('categories.deletion_class') !!} | {!! $text->text('categories.rule') !!} | {!! $text->text('categories.legal_basis') !!} | {!! $text->text('categories.action') !!} | {!! $text->text('categories.erasure') !!} | {!! $text->text('categories.responsible') !!} |
|---|---|---|---|---|---|---|---|
@foreach ($concept->entries as $entry)
| {!! $text->cell($entry->label) !!} | {!! $text->cell(implode(', ', $entry->targets)) !!} | {!! $text->period($entry->rule->period) !!}, {!! $text->text('start_events.'.$entry->startEvent->value) !!}{!! $entry->rule->counting->value === 'end_of_year' ? '*' : '' !!} | {!! $text->cell($text->rule($entry->rule, 'label')) !!} | {!! $text->cell($text->rule($entry->rule, 'legal_basis')) !!} | {!! $text->text('actions.'.$entry->action->value) !!} | {!! $text->cell($entry->rule->blocksErasure ? __('retention::concept.categories.blocking', ['ground' => $text->rule($entry->rule, 'erasure_ground')]) : $text->text('categories.not_blocking')) !!} | {!! $text->cell($entry->responsible) !!} |
@endforeach

## {!! $text->text('matrix.heading') !!}

{!! $text->text('matrix.intro') !!}

| {!! $text->text('matrix.period') !!} | {!! $text->text('start_events.collection') !!} | {!! $text->text('start_events.end_of_transaction') !!} | {!! $text->text('start_events.end_of_relationship') !!} |
|---|---|---|---|
@foreach ($concept->matrix() as $row)
| {!! $text->period($row['period']) !!} | {!! $text->cell($text->matrixCell($row['cells']['collection'])) !!} | {!! $text->cell($text->matrixCell($row['cells']['end_of_transaction'])) !!} | {!! $text->cell($text->matrixCell($row['cells']['end_of_relationship'])) !!} |
@endforeach
@if ($concept->usesEndOfYearCounting())

{!! $text->text('matrix.end_of_year') !!}
@endif

## {!! $text->text('details.heading') !!}

@foreach ($concept->entries as $entry)
### {!! $entry->label !!}

@if ($entry->description !== null)
{!! $entry->description !!}

@endif
- **{!! $text->text('details.targets') !!}:** {!! implode(', ', $entry->targets) !!}
- **{!! $text->text('details.rule') !!}:** {!! $text->rule($entry->rule, 'label') !!} ({!! $text->text('types.'.$entry->rule->type->value) !!})
- **{!! $text->text('details.period') !!}:** {!! $text->period($entry->rule->period) !!}
- **{!! $text->text('details.start_event') !!}:** {!! $text->text('start_events.'.$entry->startEvent->value) !!}
- **{!! $text->text('details.counting') !!}:** {!! $text->text('counting.'.$entry->rule->counting->value) !!}
- **{!! $text->text('details.legal_basis') !!}:** {!! $text->rule($entry->rule, 'legal_basis') !!}
- **{!! $text->text('details.action') !!}:** {!! $text->text('actions.'.$entry->action->value) !!}
- **{!! $text->text('details.erasure') !!}:** {!! $entry->rule->blocksErasure ? __('retention::concept.categories.blocking', ['ground' => $text->rule($entry->rule, 'erasure_ground')]) : $text->text('categories.not_blocking') !!}
- **{!! $text->text('details.responsible') !!}:** {!! $entry->responsible !!}
@foreach ($text->previousVersions($entry->rule) as $version)
- **{!! $text->text('details.previous') !!}:** {!! __('retention::concept.details.previous_version', ['date' => $version['until'], 'period' => $version['period']]) !!}
@endforeach
@if ($entry->blockedBy !== [])
- {!! __('retention::concept.details.blocked_by', ['relations' => implode(', ', $entry->blockedBy)]) !!}
@endif
@if ($entry->inheritsHoldsFrom !== [])
- {!! __('retention::concept.details.inherits_holds', ['relations' => implode(', ', $entry->inheritsHoldsFrom)]) !!}
@endif
@if ($text->rule($entry->rule, 'note') !== null)
- **{!! $text->text('details.note') !!}:** {!! $text->rule($entry->rule, 'note') !!}
@endif

@endforeach
## {!! $text->text('implementation.heading') !!}

### {!! $text->text('implementation.deletion_heading') !!}

{!! $text->text('implementation.deletion') !!}

### {!! $text->text('implementation.anonymisation_heading') !!}

{!! $text->text('implementation.anonymisation') !!}

### {!! $text->text('implementation.restriction_heading') !!}

{!! $text->text('implementation.restriction') !!}

### {!! $text->text('implementation.erasure_heading') !!}

{!! $text->text('implementation.erasure') !!}

### {!! $text->text('implementation.schedule_heading') !!}

{!! __('retention::concept.implementation.schedule', ['timezone' => $concept->timezone]) !!}

### {!! $text->text('implementation.log_heading') !!}

{!! $concept->logEnabled ? __('retention::concept.implementation.log', ['period' => $text->period($concept->logRetention)]) : $text->text('implementation.log_disabled') !!}

### {!! $text->text('implementation.hold_heading') !!}

{!! __('retention::concept.implementation.hold', ['period' => $text->period($concept->holdsRetention)]) !!}

## {!! $text->text('exclusions.heading') !!}

@if ($concept->excludedModels === [] && $concept->excludedTables === [])
{!! $text->text('exclusions.none') !!}
@else
{!! $text->text('exclusions.intro') !!}

| {!! $text->text('exclusions.name') !!} | {!! $text->text('exclusions.justification') !!} |
|---|---|
@foreach ([...$concept->excludedModels, ...$concept->excludedTables] as $name => $justification)
| {!! $text->cell((string) $name) !!} | {!! $text->cell($justification) !!} |
@endforeach
@endif

## {!! $text->text('systems.heading') !!}

{!! $text->configured($concept->systemsNotCovered, 'systems.text') !!}
