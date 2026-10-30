# Screenshot Guide

The staging README intentionally contains no screenshots. Add only sanitized captures from a demo Zabbix environment.

Store captures under `docs/images/`. Use lowercase kebab-case PNG names without a version suffix so that later releases can replace the same logical image. Add `-ja` before `.png` only when a separate Japanese-localized capture is required.

## Required captures

These twelve images cover the behavior a prospective user cannot fully understand from text alone.

| File | What the capture must show |
| --- | --- |
| `event-timeline-dashboard-overview.png` | A readable Timeline view with severity stacks, runtime controls, axes, period labels, and summary footer. |
| `event-timeline-tooltip.png` | A populated timeline tooltip with severity, event, host, and time details. |
| `event-timeline-by-host.png` | Host view with several hosts, severity stacks, sorting, and paging controls where applicable. |
| `event-timeline-by-host-group.png` | Host group view with multiple groups and distinct totals. |
| `event-timeline-by-event.png` | Event view showing affected-host counts and prototype grouping or individual-event behavior. |
| `event-timeline-drilldown-matching-items.png` | Drilldown opened from a bar, including event rows and matching item details. |
| `event-timeline-navigator-trigger-expansion.png` | A Navigator feeding host/trigger selections into Event Timeline, with one canonical trigger represented across multiple target hosts. |
| `event-timeline-settings-targets.png` | Targets tab: fixed/widget sources and event retrieval limit. |
| `event-timeline-settings-display.png` | Display tab: view, initial runtime state, sort, paging, and drilldown choices. |
| `event-timeline-settings-filters.png` | Filters tab: severity, acknowledgement, value type, and item type, including All choices. |
| `event-timeline-settings-time-period.png` | Time period tab: dashboard/widget time source and week-start setting. |
| `event-timeline-settings-appearance.png` | Appearance tab: graph size, axis labels, scale, and theme-aware colors. |

If space is limited, the overview may also show the runtime filters, and the Navigator capture may combine linked selection with template-trigger expansion. Do not combine any of the five settings tabs; each contains independently important options.

## Optional captures

- `event-timeline-lld-expansion.png`: one trigger prototype expanded to discovered triggers on several hosts.
- `event-timeline-runtime-filters.png`: close-up of severity, acknowledgement, value-type, item-type, and count-mode controls.
- `event-timeline-empty-state.png`: the filtered/no-events empty state with chart structure retained.
- `event-timeline-partial-result-warning.png`: a safe demo of partial trigger-matching results.
- `event-timeline-event-limit-warning.png`: event retrieval limit warning.
- `event-timeline-auto-paging.png`: page indicator and automatic page rotation.
- `event-timeline-dark-theme.png`: theme-aware colors on the Zabbix dark theme.

## Capture rules

- Use neutral demo hosts such as `web-01`, `web-02`, `db-01`, and `db-02` with synthetic event names.
- Remove or crop browser address bars, usernames, internal URLs, IP addresses, customer names, organization names, and production dashboard titles.
- Use the same time range, locale, theme, and demo dataset across related view captures.
- Include enough events to make severity, grouping, filtering, and cross-host expansion visually obvious; avoid both empty and overcrowded charts.
- Prefer PNG at 2x density. Compose overview/view images for a README width of about 750 px and settings images for about 620 px.
- The README is bilingual. Use one consistent UI language for the main set and add `-ja` variants only when localization itself needs demonstration.
- Review every image at full resolution before commit; thumbnails can hide sensitive text.
