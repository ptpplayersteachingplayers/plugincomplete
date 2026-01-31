# PTP + Salesmsg Integration

## Quick Start

### 1. Enable the Integration

```php
// Add to wp-config.php or run once
update_option('ptp_salesmsg_enabled', true);
```

### 2. Get Your API Key

```php
echo PTP_Salesmsg_API::get_api_key();
```

### 3. Use the Endpoints

Base URL: `https://yoursite.com/wp-json/ptp/v1/salesmsg/`

Authentication: `X-PTP-API-Key: ptp_xxxxx` header

## Endpoints

### Search Trainers
```
POST /search-trainers
{
  "location": "Philadelphia",
  "date": "2025-01-04",  // optional
  "max_results": 5       // optional, max 10
}
```

### Get Available Slots
```
POST /available-slots
{
  "trainer_id": 12,
  "date": "2025-01-04",
  "days_ahead": 7  // optional
}
```

### Create Booking
```
POST /create-booking
{
  "trainer_id": 12,
  "date": "2025-01-04",
  "time": "10:00",
  "parent_name": "John Smith",
  "parent_email": "john@example.com",
  "parent_phone": "+12155551234",  // optional
  "player_name": "Jake Smith",
  "player_age": 12,               // optional
  "location": "Valley Forge Park", // optional
  "notes": "Focus on dribbling"    // optional
}
```

Returns checkout URL for payment.

### Get Trainer Details
```
GET /trainer/12
```

### Get All Trainers
```
GET /trainers-summary
```

## Security

- Disabled by default
- API key authentication
- All inputs sanitized
- Prepared statements for database queries
