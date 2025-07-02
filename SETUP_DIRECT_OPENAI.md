# Setup: Direct OpenAI Integration

Diese Anleitung zeigt, wie du das Plugin für die direkte Nutzung der OpenAI API konfigurierst.

## Voraussetzungen

- OpenAI API Key
- MySQL 5.7+ (alle Versionen unterstützt)
- Shopware 6.5+

## 1. Plugin Installation

```bash
# Plugin installieren und aktivieren
bin/console plugin:install --activate ShopwareVectorSearch

# Datenbank-Migrations ausführen
bin/console dal:refresh
bin/console cache:clear
```

## 2. OpenAI API Key konfigurieren

### Option A: Über System Config (empfohlen)
```php
// config/packages/shopware.yaml oder in der Admin
'ShopwareVectorSearch' => [
    'config' => [
        'embeddingMode' => 'openai_direct',
        'openAiApiKey' => 'sk-your-openai-api-key-here',
        'batchSize' => 100,
        'defaultSimilarityThreshold' => 0.7,
        'maxSearchResults' => 20,
        'embeddingTimeout' => 30
    ]
]
```

### Option B: Über Database  
```sql
INSERT INTO system_config (id, configuration_key, configuration_value, sales_channel_id, created_at) 
VALUES (
    UNHEX(REPLACE(UUID(), '-', '')),
    'ShopwareVectorSearch.config.embeddingMode',
    '{"_value": "openai_direct"}',
    NULL,
    NOW()
);

INSERT INTO system_config (id, configuration_key, configuration_value, sales_channel_id, created_at) 
VALUES (
    UNHEX(REPLACE(UUID(), '-', '')),
    'ShopwareVectorSearch.config.openAiApiKey', 
    '{"_value": "sk-your-openai-api-key-here"}',
    NULL,
    NOW()
);
```

## 3. Produkte indexieren

```bash
# Alle Produkte indexieren
bin/console shopware:vector-search:index

# Status prüfen
bin/console shopware:vector-search:status
```

## 4. Suche testen

### Via Console Command
```bash
bin/console shopware:vector-search:search "Gaming Laptop" --limit=5 --detailed
```

### Via API
```bash
# Sales Channel Access Key aus Admin holen
# Admin → Sales Channels → [Channel] → API access → Access Key

curl -X POST "https://your-shop.com/vector-search/search" \
  -H "sw-access-key: SWSCVJY3RJFENTUZZDMZNWFWMA" \
  -H "Content-Type: application/json" \
  -d '{
    "query": "Gaming Laptop",
    "limit": 10,
    "threshold": 0.7
  }'
```

**Response:**
```json
{
  "success": true,
  "data": {
    "query": "Gaming Laptop", 
    "results": [
      {
        "product_id": "01234567890abcdef",
        "similarity": 0.85,
        "distance": 0.15,
        "content": "Gaming Laptop ASUS ROG Strix..."
      }
    ],
    "count": 5,
    "limit": 10,
    "threshold": 0.7
  }
}
```

## 5. Health Check

```bash
curl "https://your-shop.com/vector-search/health"
```

**Healthy Response:**
```json
{
  "success": true,
  "status": "healthy",
  "data": {
    "embedding_service": {
      "status": "healthy",
      "mode": "openai_direct", 
      "configured": true
    },
    "database": {
      "status": "healthy",
      "embeddings_count": 1651,
      "table_exists": true
    },
    "plugin_version": "1.0.0"
  }
}
```

## API-Authentifizierung

Die API verwendet den `sw-access-key` Header:

1. **Sales Channel Access Key finden:**
   - Shopware Admin → Sales Channels  
   - Gewünschten Channel auswählen
   - "API access" Tab → Access Key kopieren

2. **In API-Requests verwenden:**
   ```bash
   -H "sw-access-key: YOUR_SALES_CHANNEL_ACCESS_KEY"
   ```

## Performance-Optimierung

### MySQL Version prüfen
```bash
bin/console shopware:vector-search:debug
```

**MySQL 8.0.28+**: Native VECTOR-Funktionen (~50ms Suchzeit)  
**MySQL <8.0.28**: JSON-Fallback (~200ms Suchzeit)

### Batch-Größe anpassen
```php
'ShopwareVectorSearch.config.batchSize' => 50  // Für kleinere Server
```

### OpenAI API Limits
- **text-embedding-3-small**: 1000 requests/minute, 100 texts/request
- **text-embedding-3-large**: 1000 requests/minute, 100 texts/request  
- **Batch-Verarbeitung**: Automatisch für bessere Performance

## Troubleshooting

### "Invalid OpenAI API Key"
```bash
# API Key prüfen
bin/console shopware:vector-search:status

# Neu setzen
bin/console config:set ShopwareVectorSearch.config.openAiApiKey "sk-new-key"
```

### "Rate limit exceeded"  
```bash
# Kleinere Batch-Größe verwenden
bin/console shopware:vector-search:index --batch-size=20
```

### "No search results"
```bash
# Embeddings prüfen
bin/console shopware:vector-search:status

# Threshold senken
bin/console shopware:vector-search:search "query" --threshold=0.5
```

### "MySQL version compatibility"
```bash
# MySQL Version prüfen
bin/console shopware:vector-search:debug

# Zeigt automatischen Fallback-Modus
```

## Kosten-Optimierung

### Embedding-Kosten (OpenAI)
- **text-embedding-3-small**: $0.02 / 1M tokens
- **text-embedding-3-large**: $0.13 / 1M tokens

### Beispiel-Kalkulation (1000 Produkte)
- Durchschnittlich 200 tokens pro Produkt
- **small**: 1000 × 200 × $0.02/1M = ~$0.004
- **large**: 1000 × 200 × $0.13/1M = ~$0.026

### Text-Optimierung
Das Plugin optimiert automatisch:
- Entfernt HTML-Tags und Steuerzeichen
- Begrenzt Text auf ~6000 Zeichen pro Produkt
- Kombiniert Name, Beschreibung, Hersteller, Kategorien, Properties

## Monitoring

### Status überwachen
```bash
# Regelmäßiger Status-Check
bin/console shopware:vector-search:status --short

# Health-Check via API  
curl "https://your-shop.com/vector-search/health"
```

### Logs aktivieren
```php
'ShopwareVectorSearch.config.enableLogging' => true
```

### Performance-Metriken
```bash
# Detaillierte Suche mit Timing
bin/console shopware:vector-search:search "query" --detailed
``` 