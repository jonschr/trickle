---
name: Trickle
description: A WordPress transfer workbench with measured progress and explicit destination context.
colors:
  primary: "#2271b1"
  primary-text: "#165c94"
  primary-wash: "#e7effa"
  rail-selected: "#e8f0fb"
  ink: "#1e2939"
  muted: "#647184"
  line: "#dce4ed"
  surface: "#fff"
  rail: "#f6f8fc"
  table-header: "#f5f8fc"
  field-border: "#c9d4e2"
  telemetry: "#172b43"
  telemetry-text: "#edf5ff"
  telemetry-muted: "#b7c8dc"
  pending: "#263d57"
  received: "#315b82"
  transfer-line: "#80bdff"
  verified: "#a587c1"
  chart-grid: "#3b526d"
  chart-tick: "#bfd0e3"
  chart-error: "#ffaaa5"
  prepare-line: "#becfe1"
  prepare-wash: "#354b64"
  replace-line: "#efc77a"
  replace-wash: "#58482e"
  health-line: "#85d0bd"
  health-wash: "#28584f"
  size-small: "#edfaef"
  size-small-text: "#185b2a"
  size-medium: "#fcf3cf"
  size-medium-text: "#694c00"
  size-large: "#fce8e8"
  size-large-text: "#8a2424"
typography:
  headline:
    fontSize: "26px"
    fontWeight: 600
    lineHeight: 1.4
    letterSpacing: "-.02em"
  title:
    fontSize: "21px"
    fontWeight: 600
    lineHeight: 1.4
    letterSpacing: "-.02em"
  monitor-title:
    fontSize: "22px"
    fontWeight: 600
    letterSpacing: "-.02em"
  metric:
    fontSize: "22px"
    fontWeight: 550
    lineHeight: 1.4
    letterSpacing: "-.02em"
  body:
    fontSize: "13px"
    lineHeight: 1.5
  supporting:
    fontSize: "12px"
    lineHeight: 1.6
  control:
    fontSize: "12px"
  table:
    fontSize: "11px"
  label:
    fontSize: "10px"
  replacement:
    fontFamily: "ui-monospace, SFMono-Regular, Consolas, monospace"
    fontSize: "11px"
rounded:
  badge: "4px"
  control: "6px"
  endpoint: "10px"
  panel: "11px"
  telemetry: "12px"
spacing:
  compact: "7px"
  control: "12px"
  small: "14px"
  medium: "18px"
  panel: "20px"
  large: "22px"
  section: "24px"
  telemetry: "28px"
components:
  button-primary:
    backgroundColor: "{colors.primary}"
    textColor: "{colors.surface}"
    rounded: "{rounded.control}"
    padding: "4px 12px"
    typography: "{typography.control}"
  button-secondary:
    rounded: "{rounded.control}"
    padding: "4px 12px"
    typography: "{typography.control}"
  input-search:
    rounded: "{rounded.control}"
    height: "34px"
    typography: "{typography.control}"
  navigation-active:
    backgroundColor: "{colors.primary-wash}"
    textColor: "{colors.primary-text}"
    rounded: "{rounded.control}"
    padding: "8px 13px"
  size-chip:
    rounded: "{rounded.badge}"
    padding: "3px 6px"
    typography: "{typography.label}"
  component-rail:
    backgroundColor: "{colors.rail}"
    rounded: "{rounded.panel}"
    width: "260px"
  telemetry-card:
    backgroundColor: "{colors.telemetry}"
    textColor: "{colors.telemetry-text}"
    rounded: "{rounded.telemetry}"
    padding: "26px 28px 22px"
  pipeline-card:
    backgroundColor: "{colors.surface}"
    rounded: "{rounded.panel}"
    padding: "22px 20px 15px"
  recent-activity:
    typography: "{typography.table}"
---

# Design System: Trickle

## Overview

**Creative North Star: "The transfer workbench"**

Trickle gives administrators a compact, deliberate workspace for consequential transfers. Source and destination remain explicit, selection details sit beside comparison data, and primary actions retain WordPress blue. The interface uses short labels, quiet supporting text, thin dividers, and rounded white surfaces.

Monitoring stays on a compact white surface. A single weighted component bar distinguishes pending, transferred and replaced work. Pipeline and recent activity explain the operation beneath the chart. This document records the implemented migration workspace and monitor; the shared settings, backups, and diagnostics components remain an incumbent system rather than a newly redesigned surface.

**Key Characteristics:**
- Dense comparison data with inclusion, counts and source sizes consolidated in the component tabs.
- White, bordered setup surfaces and a navy monitoring canvas.
- Measured progress, separate verification state, and readable activity history.
- WordPress blue actions and concise operational copy.

## Colors

The light workspace uses cool neutrals; the monitor uses blue-gray layering with state color inside the navy canvas. Frontmatter values are normative.

### Primary

- **WordPress blue** (`primary`) identifies primary actions, focus outlines, links, and active pipeline progress.
- **Deep action blue**, **selected blue wash**, and **selected rail wash** (`primary-text`, `primary-wash`, `rail-selected`) identify navigation and the current component without heavy framing.

### Secondary

- **Verified lavender** (`verified`) marks verified stream ranges and verified pipeline tracks. It does not mean merely received.
- **Transfer ice**, **prepare mist**, **replace amber**, and **health mint** are the stage line colors on the dark canvas; their paired washes fill processed ranges. The lighter page tabs retain their existing stage colors from the shared stylesheet.
- Small, medium, and large size chips use their named green, amber, and red foreground/background pairs. These express relative sizes, not transfer failure.

### Neutral

- **Workspace ink** and **slate supporting text** (`ink`, `muted`) establish the light reading hierarchy.
- **Surface white**, **rail frost**, **table frost**, **cool divider**, and **field border** (`surface`, `rail`, `table-header`, `line`, `field-border`) separate structured work areas.
- **Telemetry navy**, **telemetry text**, and **telemetry supporting text** establish the monitoring material. `pending`, `received`, `chart-grid`, and `chart-tick` provide the plot layers. `chart-error` is the chart's error color.

**The Verification Rule.** Received work and verified work must remain visually distinct; lavender is reserved for confirmed stream verification.

## Typography

The interface inherits the WordPress admin font family. It does not load or define a display family. Replacement textareas use the explicit monospace family in the frontmatter.

The hierarchy is compact and practical: the setup headline leads, component titles name the current workspace, and metrics become the dominant text during monitoring. Use the frontmatter roles rather than applying the headline scale to every section.

- **Headline:** setup title; reduces to 23px at the narrow breakpoint.
- **Title:** current component heading.
- **Monitor title:** current operation; reduces to 20px on narrow screens.
- **Metric:** monitoring values; reduces to 18px on narrow screens.
- **Body / supporting:** inherited interface text and short explanations. Table content uses the smaller table role.
- **Label:** metadata, chart labels, and secondary counts. The implementation also contains 9px pipeline notes and size legends; these are local density decisions, not a general body-text role.

## Layout

The centered wrapper is capped at 1360px, with padding of 24px 28px 48px. The header aligns identity, navigation, and connection-code action. The local card stays on the left and the remote card on the right. A 72px center button shows the direction arrow with Pull/Push below; clicking reverses direction.

Site identity boxes keep the current site first (left on desktop, above on mobile) and the remote second. The arrow points toward the destination: right/down for push, left/up for pull. Source and Destination labels follow their actual roles.

The Migrate route owns remote selection. An empty remote is a dashed card with Add source; a connected remote has a three-dot menu for saved sites, adding/updating connections and refreshing versions. Connection fields use a 540px native modal with inline errors, automatic connection after a complete code, and private-code clearing on close. This site’s public identity is available before any remote request. Preset and Recent share a compact row, stacking on narrow screens. Route changes are disabled while a transfer is retained.

Setup uses one comparison workspace with a 260px component rail. Each of the four 88px rows combines inclusion, selection count and estimated source size in MB. The primary transfer action sits immediately beneath these rows. There is no separate summary sidebar or source-size strip. The workbench has a 585px minimum height. Database tables retain a 610px minimum width, horizontal scrolling, a 420px maximum scroll height, and sticky headings. Plugin/theme comparisons use a compact identity column and an installed/incoming comparison column; rows are 32px on desktop and 36px on mobile, with a 680px minimum width and horizontal scrolling.

Monitoring opens in a native modal dialog, up to 850px wide with a dimmed backdrop. One weighted component bar, notices and activity appear without duplicating controls. The site route stays on the setup screen. The header and cancellation stay outside its scrollable body; active work cannot be dismissed with Escape or backdrop clicks. Completion groups outline View backup, Done, and blue View site in one footer row, with View site rightmost. It omits the redundant destination-updated sentence. The component bar is 22px high. Compact component labels pair names with sizes and text states. Pending estimates keep their weights and use ~ until their own stream is prepared. The color-explanation legend is omitted. Activity uses a native details control, starts open, and scrolls after 220px. Transfer activity groups routine events by plugin, theme, media year or database table, retaining up to 64 milestones and 16 issues. Exact filenames remain available for failures.

- **1200px:** wrapper horizontal padding 20px and component rail 245px.
- **1000px:** header navigation moves to a second row.
- **782px:** inherited rules stack route endpoints and expose source/destination labels in component rows.
- **700px:** wrapper padding 16px 12px 32px; component tabs become a 108px horizontal strip with source sizes, followed by the transfer action. The dialog uses 8px viewport margins and a scrolling body; the component legend uses two columns. The first table column keeps a 180px minimum width.
- **600px:** compact labels retain readable spacing; there are no monitoring stage tabs.

The component rail and modal are migration composition choices, not mandatory layouts for other plugin surfaces.

## Elevation & Depth

The migration workspace uses flat surfaces, one-pixel borders, and tonal layering. The modal uses one offset shadow over a dimmed backdrop; setup and monitoring both use flat white surfaces. Focus uses a two-pixel blue outline with a three-pixel offset; focus does not add a shadow. Existing WordPress control styling can supply its own native state treatments.

Component fill changes use a 0.35s ease-out width transition, disabled under reduced-motion preferences.

## Shapes

Controls use the control radius, endpoints use the endpoint radius, workbench/pipeline surfaces use the panel radius, and the navy canvas uses the telemetry radius. The rail and canvas clip their contents. Chips are compact, gently rounded rectangles rather than pills. Dividers and quiet border colors carry structure inside these rounded outer boundaries.

## Components

### Buttons

Primary actions retain the WordPress blue treatment. Shared buttons use a 35px minimum height; the wide-screen transfer action is at least 44px tall and fills the rail width. Secondary buttons retain WordPress control states with the shared rounded shape and compact padding. Focus stays visible. Labels state the operation and destination, such as “Pull into this site.”

### Inputs / Fields

Search fields and selects use the field border and control radius. Search tools are 34px tall, with the search width capped at 265px. Native labels and controls remain available to keyboard users. Replacement textareas use monospace; source/destination values appear side by side on the comparison surface.

### Navigation

Top-level tabs are compact text controls with a selected blue wash and no surrounding tab border. Monitoring has no stage tabs. One status line summarizes current work, with a single elapsed timer beside the modal title.

### Chips

Relative-size chips use the small, medium, and large color pairs. They supplement numeric sizes rather than replacing the values. Update badges and local-site labels retain their existing distinct roles.

### Cards / Containers

Component selection and source-size context appear together in the tab rail. The action sits beneath the four tabs; it moves into the dialog only when an interrupted transfer needs a resume action. Endpoint cards make the route readable before selection. Shared disclosure cards elsewhere retain the incumbent 8px radius and existing padding.

### Component Rail and Comparison Workspace

Each rail entry has a separate inclusion checkbox and a full-row navigation button containing its name, estimated source size in MB, selection count, and caret. The full row always opens the component, including unchecked components; repeated clicks never collapse it or change inclusion. Only the current component body is shown. The active rail entry uses a light blue wash. Plugin/theme rows use two columns: component identity and installed → incoming version effect. Matching versions remain unhighlighted, even when selected. Upgrades use a green wash and upward arrow; downgrades use a red wash and downward arrow. New installs use a neutral plus. Outcome words are available through tooltips and accessible labels. Each version has a filled/hollow activation icon; active parents use a concentric-circle icon. Versions, activation, sizes and available updates stay on one line. Inactive incoming names and artwork are muted, without dimming warnings. Update-available notices stay separate from installed versions. Database replacement rules belong below the database table list.

### Telemetry Canvas

The component bar uses relative uncompressed sizes. Pending segments are light, transferred portions use half-tone color, verified streams use full color, and replaced portions are gold. Receipts, replacement counts and published size metadata retain per-transfer high-water marks under late polls and reloads. Segment weights stay fixed after preparation; verification never resets transfer fill. Database transfer follows its own acknowledged stream. Plugin/theme/media fills use exact compressed component boundaries in the shared file stream, never the overall percentage copied into each component. Older snapshots without boundaries keep component fills unknown and show a single whole-stream percentage. Replacement fill uses actual per-component completed-file counts, not byte or file-order guesses. Text states and accessible descriptions accompany every selected component; color is not the only status cue.

### Transfer Activity

Transfer activity sits below the bar in a native details control styled like Destination changes. It starts open and keeps the user’s open/closed choice while polling. Compact desktop rows emphasize the plugin, theme, year or table name, with a muted time and operation; mobile rows wrap the operation beneath the name. Installed-state verification runs before completion; failures retain exact paths and copyable details.

## Do's and Don'ts

### Do:

- **Do** retain WordPress blue for primary transfer actions and visible keyboard focus.
- **Do** preserve source, destination, and selected-component context throughout the workflow.
- **Do** distinguish received bytes from verified streams using both color and text.
- **Do** show measured units and omit unsupported speed or ETA estimates.
- **Do** keep the component bar visible and activity available in its collapsible section.
- **Do** preserve the existing shared components when extending settings, backups, and diagnostics.

### Don't:

- **Don't** apply the dark telemetry material to the entire setup workspace.
- **Don't** mark received bytes as verified before stream verification succeeds.
- **Don't** squeeze comparison tables into unreadable mobile columns; preserve horizontal scrolling.
- **Don't** promote the migration rail and modal arrangement into a requirement for unrelated plugin surfaces.
