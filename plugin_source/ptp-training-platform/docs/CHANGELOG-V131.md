# PTP Training Platform v131.0 - Group Session Smart Checkout

## Release Date: January 2025

## Overview
This release adds intelligent multi-player checkout support for group training sessions. When a training has multiple player spots (group_size > 1 or a trainer-created group session), the checkout now dynamically shows the correct number of player input fields and the thank you page displays all registered players.

## New Features

### 1. Multi-Player Checkout Form
- **Dynamic Player Cards**: When `group_size > 1` or a `group_session_id` is provided, the checkout displays individual player cards for each spot
- **Saved Player Selection**: Each player slot can select from saved players or enter new player info
- **Copy from Player 1**: Quick action to copy team/skill from first player to subsequent players
- **Per-Player Pricing Display**: Shows price breakdown per player for group sessions

### 2. Group Session Support
- **URL Parameters**: Accepts `group_session_id`, `spots`, and `group_size` parameters
- **Available Spots Detection**: Automatically limits spots to available capacity in trainer-created group sessions
- **Group Session Info Banner**: Displays session title, price per player, and available spots

### 3. Updated Thank You Page
- **Multi-Player Display**: Shows all registered players in a formatted list with numbered badges
- **Smart Formatting**: Displays "Player 1, Player 2 & Player 3" style names for multiple campers
- **Session Data Recovery**: Properly recovers multi-player data from checkout session transient

## Technical Changes

### ptp-checkout.php
- Added `$group_session` loading from `ptp_group_sessions` table
- Extended `$group_size` support up to 10 players
- Added `$is_multi_player` flag to control UI rendering
- New multi-player form with `players[N][field]` array structure
- JavaScript functions: `selectSavedPlayer()`, `copyFromPlayer1()`, `validateMultiPlayerForm()`, `collectMultiPlayerData()`
- Updated `validateRequiredFields()` to handle multi-player validation

### class-ptp-unified-checkout.php
- Added `$players_data` array capture from `$_POST['players']`
- Added `group_player_count`, `group_session_id`, `group_size` to checkout transient data
- Updated validation to support multi-player forms

### thank-you-v100.php
- Added multi-player data extraction from checkout session
- Updated `$all_campers` population from `players_data` array
- New multi-player display section with numbered player badges
- Added `group_size`, `group_session_id`, `players_data` to fallback booking object

## URL Parameters

| Parameter | Description | Example |
|-----------|-------------|---------|
| `group_size` | Number of player spots (1-10) | `?group_size=3` |
| `spots` | Alias for group_size | `?spots=4` |
| `group_session_id` | ID of trainer-created group session | `?group_session_id=42` |
| `trainer_id` | Required - trainer's ID | `?trainer_id=5` |

## Example Usage

### Private Group Training (Duo/Trio/Quad)
```
/checkout/?trainer_id=5&date=2025-02-01&time=10:00&group_size=3
```
Shows 3 player input cards with group pricing multiplier applied.

### Trainer-Created Group Session
```
/checkout/?group_session_id=42&spots=2
```
Loads session details from database, shows 2 player input cards with per-player pricing.

## Database Requirements
- Requires `ptp_group_sessions` table (created by PTP_Groups class)
- Uses existing `ptp_players` and `ptp_parents` tables

## Backward Compatibility
- Single player checkout unchanged
- Existing `group_size=1` parameter still works as before
- All existing checkout flows remain functional
