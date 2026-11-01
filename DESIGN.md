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
  summary-card:
    backgroundColor: "{colors.surface}"
    rounded: "{rounded.panel}"
    padding: "23px 20px"
    width: "270px"
  component-rail:
    backgroundColor: "{colors.rail}"
    rounded: "{rounded.panel}"
    width: "171px"
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

Monitoring changes the working material to a dark navy telemetry canvas. Bright measurements and muted chart scaffolding make received, pending, and verified work distinguishable. Pipeline and recent activity explain the operation beneath the chart. This document records the implemented migration workspace and monitor; the shared settings, backups, and diagnostics components remain an incumbent system rather than a newly redesigned surface.

**Key Characteristics:**
- Dense comparison data with a persistent wide-screen selection summary.
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

The centered wrapper is capped at 1360px, with padding of 24px 28px 48px. The header aligns identity, navigation, and connection-code action. Source and destination share a route row around an arrow.

Site identity boxes keep the current site first (left on desktop, above on mobile) and the remote second. The arrow points toward the destination: right/down for push, left/up for pull. Source and Destination labels follow their actual roles.

Setup places a flexible comparison area beside a 270px summary with a 20px gap. The summary sticks below the WordPress admin bar with a 16px offset. A 171px component rail borders the current component; its rows are 70px tall and the workbench has a 585px minimum height. Database tables retain a 610px minimum width, horizontal scrolling, a 420px maximum scroll height, and sticky headings. Plugin/theme comparison columns use a 1.4:1:1 proportion.

Monitoring becomes full width, with a 230px transfer plot or 84px processing plot. Pipeline and activity occupy equal columns with a 29px gap; activity scrolls after 360px.

- **1200px:** wrapper horizontal padding 20px, summary 238px, rail 151px, workspace gap 16px.
- **1050px:** summary follows comparison, loses stickiness, and uses a four-column breakdown.
- **1000px:** header navigation moves to a second row.
- **782px:** inherited rules stack route endpoints and expose source/destination labels in component rows.
- **700px:** wrapper padding 16px 12px 32px; rail becomes a 65px horizontal strip; summary breakdown uses two columns; pipeline/activity stack; telemetry padding becomes 20px 15px; transfer plot becomes 190px. The first table column keeps a 180px minimum width.
- **600px:** inherited rules tighten stage labels and widen the transfer tab proportionally.

The rail and summary are migration composition choices, not mandatory layouts for other plugin surfaces.

## Elevation & Depth

The migration workspace uses flat surfaces, one-pixel borders, and tonal layering. There is no custom card-shadow vocabulary. The summary stays visible through position, and the monitor gains emphasis through its navy material. Focus uses a two-pixel blue outline with a three-pixel offset; focus does not add a shadow. Existing WordPress control styling can supply its own native state treatments.

Progress fill changes use a 0.6s cubic-bezier(.16,1,.3,1) transition; the verification track uses 0.4s ease-out. Both disable their transitions under reduced-motion preferences. Chart rendering and measured updates should preserve these existing behaviors.

## Shapes

Controls use the control radius, endpoints use the endpoint radius, workbench/summary/pipeline surfaces use the panel radius, and the navy canvas uses the telemetry radius. The rail and canvas clip their contents. Chips are compact, gently rounded rectangles rather than pills. Dividers and quiet border colors carry structure inside these rounded outer boundaries.

## Components

### Buttons

Primary actions retain the WordPress blue treatment. Shared buttons use a 35px minimum height; the wide-screen transfer action is at least 41px tall and fills the summary width. Secondary buttons retain WordPress control states with the shared rounded shape and compact padding. Focus stays visible. Labels state the operation and destination, such as “Pull into this site.”

### Inputs / Fields

Search fields and selects use the field border and control radius. Search tools are 34px tall, with the search width capped at 265px. Native labels and controls remain available to keyboard users. Replacement textareas use monospace; source/destination values appear side by side on the comparison surface.

### Navigation

Top-level tabs are compact text controls with a selected blue wash and no surrounding tab border. Stage navigation is a separate four-part row: Prepare, Transfer & verify, Replace, and Health. The selected stage uses its matching wash and a colored lower border; unavailable stages remain disabled. Observed elapsed times sit beside their stage labels; unavailable times leave no empty timer row. Users can inspect prior stages while live following is explicit.

### Chips

Relative-size chips use the small, medium, and large color pairs. They supplement numeric sizes rather than replacing the values. Update badges and local-site labels retain their existing distinct roles.

### Cards / Containers

The selection summary is a white bordered card with per-component counts, measured-size context, verification/backup copy, a primary action, and destination identity. Endpoint cards make the route readable before selection. Shared disclosure cards elsewhere retain the incumbent 8px radius and existing padding.

### Component Rail and Comparison Workspace

Each rail entry has a component inclusion checkbox, name, selection summary, and disclosure control. Only the current component body is shown. The active rail entry uses a light blue wash. Comparison rows keep source and destination information aligned; database replacement rules belong below the database table list.

### Telemetry Canvas

Measured bytes, speed, elapsed time, and remaining time use explicit units. Speed and ETA appear only when progress supports them. The transfer chart uses bytes on its horizontal axis and speed on its vertical axis; count-based stages show completed work instead. Pending, received, and verified ranges stay distinct. The chart supports pointer inspection and focus plus arrow-key inspection.

### Pipeline and Recent Activity

The pipeline lists database, files, replacement, and health with text states beside their tracks. Verified streams turn lavender. Recent activity remains beside or below the pipeline and shows time, event, optional path, and recovery/retry context. It is not hidden inside an accordion.

## Do's and Don'ts

### Do:

- **Do** retain WordPress blue for primary transfer actions and visible keyboard focus.
- **Do** preserve source, destination, and selected-component context throughout the workflow.
- **Do** distinguish received bytes from verified streams using both color and text.
- **Do** show measured units and omit unsupported speed or ETA estimates.
- **Do** keep pipeline and recent activity visible in monitoring, stacking them on narrow screens.
- **Do** preserve the existing shared components when extending settings, backups, and diagnostics.

### Don't:

- **Don't** apply the dark telemetry material to the entire setup workspace.
- **Don't** mark received bytes as verified before stream verification succeeds.
- **Don't** squeeze comparison tables into unreadable mobile columns; preserve horizontal scrolling.
- **Don't** promote the migration rail and summary arrangement into a requirement for unrelated plugin surfaces.
