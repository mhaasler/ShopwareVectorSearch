# Shopware Vector Search Plugin

🔍 **KI-gestützte Vektorsuche für Shopware 6** - Semantic Product Search mit OpenAI Embeddings

[![PHP](https://img.shields.io/badge/PHP-8.1%2B-blue.svg)](https://php.net)
[![Shopware](https://img.shields.io/badge/Shopware-6.6%2B%20%7C%206.7%2B-blue.svg)](https://shopware.com)
[![MySQL](https://img.shields.io/badge/MySQL-8.0.28%2B-orange.svg)](https://mysql.com)
[![OpenAI](https://img.shields.io/badge/OpenAI-text--embedding--ada--002-green.svg)](https://openai.com)

## 🚀 Features

- **🧠 Semantic Search**: Intelligente Produktsuche basierend auf Bedeutung, nicht nur Keywords
- **⚡ MySQL Vector Support**: Nutzt native MySQL 8.0+ VECTOR Datentypen für optimale Performance
- **🔗 Flexible Embedding-Modi**: Unterstützt sowohl externe Embedding-Services als auch direkte OpenAI API
- **📊 1536-dimensionale Vektoren**: Kompatibel mit OpenAI's `text-embedding-ada-002` Modell
- **🔄 Auto-Reindexierung**: Automatische Aktualisierung bei Produktänderungen
- **⚙️ Admin Interface**: Vollständige Konfiguration über Shopware Admin
- **🌐 REST API**: Admin API und Storefront API Endpoints
- **📈 Performance Optimiert**: Batch-Verarbeitung und intelligentes Caching

## 🎯 Use Cases

- **E-Commerce Suche**: "rotes Kleid für Hochzeit" findet auch "burgundfarbenes Abendkleid"
- **Produktempfehlungen**: Ähnliche Produkte basierend auf semantischer Ähnlichkeit
- **Cross-Selling**: Intelligente Produktverknüpfungen
- **Mehrsprachige Suche**: Funktioniert auch bei verschiedenen Sprachen

## ⚡ Performance & Kompatibilität

Das Plugin **funktioniert mit allen MySQL-Versionen** und wählt automatisch den optimalen Modus:

### 🚀 Native VECTOR Mode (MySQL 8.0.28+)
- **Performance**: Optimal (Hardware-beschleunigt)
- **Speicher**: Kompakt (native VECTOR Datentyp)
- **Suchzeit**: < 50ms bei 10,000+ Produkten

### ⚡ JSON Fallback Mode (MySQL < 8.0.28)  
- **Performance**: Gut (Software Cosine-Similarity)
- **Speicher**: Etwas mehr (JSON-Speicherung)
- **Suchzeit**: < 200ms bei 10,000+ Produkten

**💡 Tipp**: Nutze `bin/console shopware:vector-search:debug` um herauszufinden, welcher Modus verwendet wird.

## 📋 Requirements

### System Requirements
- **PHP**: 8.1 oder höher
- **Shopware**: 6.6.x oder 6.7.x
- **MySQL**: 5.7+ (funktioniert mit allen Versionen)
- **Memory**: Mindestens 512MB für PHP

### MySQL Version Support
| Version | Unterstützung | Performance | Bemerkung |
|---------|--------------|-------------|-----------|
| **MySQL 8.0.28+** | ✅ Vollständig | 🚀 **Optimal** | Native VECTOR Datentypen |
| **MySQL 8.0.0-8.0.27** | ✅ Vollständig | ⚡ Gut | JSON-Fallback mit Cosine-Similarity |
| **MySQL 5.7+** | ✅ Vollständig | ⚡ Gut | JSON-Fallback mit Cosine-Similarity |

### Optional für bessere Performance
- **Composer**: 2.x für optimierte Autoloading

## 🛠️ Installation

### 1. Plugin installieren

```bash
# Option A: Über Composer (empfohlen)
composer require mhaasler/shopware-vector-search

# Option B: Manuel download und extrahieren
# Lade das Plugin herunter und extrahiere es nach custom/plugins/ShopwareVectorSearch/
```

### 2. Plugin aktivieren

```bash
# Plugin installieren und aktivieren (Dependencies werden automatisch installiert)
bin/console plugin:install --activate ShopwareVectorSearch

# Cache leeren
bin/console cache:clear
```

### 3. Datenbank-Migration ausführen

```bash
# Migrations ausführen
bin/console database:migrate --all ShopwareVectorSearch
```

## ⚙️ Konfiguration

### Admin Konfiguration

Gehe zu **Einstellungen → System → Plugins → Vector Search → Konfiguration**

#### Embedding Mode wählen:

**🌐 External Embedding Service** (Standard)
- Nutzt einen separaten Embedding-Server
- Flexibler bei Modellwahl
- Kosteneffizienter bei hohem Volumen

**🤖 Direct OpenAI API**
- Direkte Verbindung zur OpenAI API
- Einfacher zu verwalten
- Höhere Zuverlässigkeit

### Konfigurationsmöglichkeiten

| Einstellung | Beschreibung | Standard |
|-------------|--------------|----------|
| **Embedding Mode** | Auswahl zwischen Service/Direct | `External Service` |
| **Embedding Service URL** | URL des externen Services | `http://localhost:8001` |
| **OpenAI API Key** | Dein OpenAI API Schlüssel | - |
| **Vector Search aktivieren** | Hauptschalter | `true` |
| **Batch-Größe** | Produkte pro Indexierungsvorgang | `100` |
| **Ähnlichkeitsschwelle** | Minimale Ähnlichkeit für Ergebnisse | `0.7` |
| **Max. Suchergebnisse** | Maximale Anzahl Ergebnisse | `20` |
| **Request Timeout** | Timeout in Sekunden | `30` |
| **Debug-Logging** | Detaillierte Logs aktivieren | `false` |
| **Auto-Reindex** | Auto-Neuindexierung bei Änderungen | `true` |

## 🚀 Quick Start

### 1. Embedding Service starten (für External Mode)

```bash
# Wenn du den External Embedding Service Mode nutzt
python openai_embedding_service.py
```

### 2. Oder OpenAI API Key konfigurieren (für Direct Mode)

1. Erstelle einen OpenAI Account: [platform.openai.com](https://platform.openai.com)
2. Generiere einen API Key
3. Trage den Key in der Plugin-Konfiguration ein

### 3. Produkte indexieren

```bash
# Alle Produkte für Vector Search indexieren
bin/console shopware:vector-search:index

# Mit benutzerdefinierten Parametern
bin/console shopware:vector-search:index --batch-size=50 --force
```

### 4. Testen

```bash
# Console Test
bin/console shopware:vector-search:search "rotes Kleid" --limit=5 --detailed

# Status prüfen
bin/console shopware:vector-search:status

# MySQL Debug (empfohlen vor erstem Einsatz)
bin/console shopware:vector-search:debug

# API Test (mit Sales Channel Access Key)
curl -X POST "https://your-shop.com/vector-search/search" \
  -H "sw-access-key: YOUR_SALES_CHANNEL_ACCESS_KEY" \
  -H "Content-Type: application/json" \
  -d '{"query": "rotes Kleid", "limit": 5, "threshold": 0.7}'

# Health Check Test
curl "https://your-shop.com/vector-search/health"
```

## 🔧 Console Commands

### Produkte indexieren
```bash
bin/console shopware:vector-search:index [options]

Options:
  -b, --batch-size=SIZE    Number of products to process in each batch (default: 100)
  -f, --force              Force reindexing even if embeddings already exist
```

### Suche testen
```bash
bin/console shopware:vector-search:search <query> [options]

Arguments:
  query                    Search query to test

Options:
  -l, --limit=LIMIT        Maximum number of results (default: 10)
  -t, --threshold=THRESHOLD Similarity threshold 0.0-1.0 (default: 0.7)
  -d, --detailed           Show detailed similarity scores
```

### Status anzeigen
```bash
bin/console shopware:vector-search:status

# Zeigt an:
# - Konfiguration
# - Datenbankstatus
# - Embedding Service Status
# - Indexierungs-Fortschritt
```

### Vector-Daten löschen
```bash
bin/console shopware:vector-search:clear [options]

Options:
  -f, --force              Skip confirmation prompt
```

### MySQL Debug & Diagnostik
```bash
bin/console shopware:vector-search:debug

# Zeigt an:
# - MySQL Version und VECTOR-Support
# - Tabellen-Struktur (VECTOR vs JSON)
# - Performance-Modus (Native vs Fallback)
# - Grund für Fallback-Verwendung
```

## 📡 API Documentation

### POST /vector-search/search
Führt eine semantische Produktsuche durch.

**Authentication:** `sw-access-key` Header erforderlich

**Request:**
```bash
curl -X POST "https://your-shop.com/vector-search/search" \
  -H "sw-access-key: YOUR_SALES_CHANNEL_ACCESS_KEY" \
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

**Status Codes:**
- `200`: Erfolgreiche Suche
- `400`: Ungültige Anfrage (fehlende query)
- `401`: Ungültiger oder fehlender sw-access-key
- `500`: Server-Fehler

### GET /vector-search/health
Prüft den Systemstatus (öffentlich zugänglich).

**Request:**
```bash
curl "https://your-shop.com/vector-search/health"
```

**Response:**
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

**Status Codes:**
- `200`: System ist gesund
- `503`: System hat Probleme oder ist nicht verfügbar

### API-Authentifizierung

Die API verwendet den `sw-access-key` Header für die Authentifizierung:

1. **Sales Channel Access Key finden:**
   - Shopware Admin → Sales Channels
   - Gewünschten Channel auswählen
   - "API access" Tab → Access Key kopieren

2. **In API-Requests verwenden:**
   ```bash
   -H "sw-access-key: SWSCVJY3RJFENTUZZDMZNWFWMA"
   ```

## Management über Console Commands

Die Verwaltung des Plugins erfolgt primär über Console Commands:

```bash
# Alle Produkte indexieren
bin/console shopware:vector-search:index

# Force reindex mit Batch-Größe
bin/console shopware:vector-search:index --force --batch-size=50

# Status prüfen
bin/console shopware:vector-search:status

# Suche testen
bin/console shopware:vector-search:search "Gaming Laptop" --detailed

# Debug/MySQL Version prüfen
bin/console shopware:vector-search:debug

# Alle Daten löschen
bin/console shopware:vector-search:clear --force
```

### Status prüfen
```bash
# Plugin-Status
bin/console plugin:list | grep ShopwareVectorSearch

# Tabellen-Status prüfen
bin/console doctrine:schema:validate
```

## 🧪 Development & Testing

### Development Setup
```bash
# Plugin im Development Mode installieren
git clone <repo-url> custom/plugins/ShopwareVectorSearch
cd custom/plugins/ShopwareVectorSearch
composer install

# Plugin aktivieren
bin/console plugin:install --activate ShopwareVectorSearch
```

### Testing
```bash
# Unit Tests (falls vorhanden)
vendor/bin/phpunit

# API Tests mit Bruno/Postman
# Siehe .bruno/ Verzeichnis für vorgefertigte Requests
```

### Debug Mode
```bash
# Debug-Logging aktivieren
# In Plugin-Konfiguration: "Debug-Logging" = true

# Logs anschauen
tail -f var/log/dev.log | grep VectorSearch
```

## 📊 Performance Tipps

### MySQL Optimierung
```sql
-- Für bessere Vector-Performance
SET GLOBAL innodb_buffer_pool_size = 1073741824; -- 1GB
SET GLOBAL max_connections = 200;

-- Index-Status prüfen
SHOW INDEX FROM mh_product_embeddings;
```

### Batch-Größe optimieren
- **Kleine Shops** (< 1000 Produkte): Batch-Größe 50-100
- **Mittlere Shops** (1000-10000): Batch-Größe 100-200  
- **Große Shops** (> 10000): Batch-Größe 200-500

### Caching
- Plugin nutzt intelligentes Content-Hash basiertes Caching
- Nur geänderte Produkte werden neu indexiert
- Vector-Index für schnelle Similarity-Suche

## 🐛 Troubleshooting

### Häufige Probleme

**1. "OpenAI API Key is not configured"**
```bash
# Prüfe Konfiguration
bin/console config:get ShopwareVectorSearch.config.openAiApiKey

# API Key setzen (falls Direct Mode)
# → Admin → Plugins → Vector Search → Konfiguration
```

**2. "Embedding service URL is not configured"**
```bash
# Service starten
python openai_embedding_service.py

# URL prüfen
curl http://localhost:8001/health
```

**3. "Vector dimension mismatch"**
```bash
# Migration ausführen
bin/console database:migrate --all

# Embeddings neu generieren
bin/console shopware:vector-search:index --force-reindex
```

**4. "Class 'OpenAI\Client' not found"**
```bash
# Dependencies installieren
composer install
bin/console cache:clear
```

### Log-Analyse
```bash
# Fehler-Logs
tail -f var/log/prod.log | grep ERROR

# Vector Search spezifische Logs
tail -f var/log/dev.log | grep VectorSearch

# MySQL Slow Query Log aktivieren (für Performance-Debugging)
# my.cnf: slow_query_log = 1
```

### Performance-Debugging
```bash
# Embedding Response Times messen
time curl -X POST http://localhost:8001/embed \
  -H "Content-Type: application/json" \
  -d '{"text": "test product"}'

# MySQL Vector Query Performance
EXPLAIN SELECT * FROM mh_product_embeddings 
WHERE VECTOR_DISTANCE(embedding, '[1,2,3...]') < 0.3;
```

## 📚 Weitere Dokumentation

- **[Flexible Embedding Setup](SETUP_FLEXIBLE_EMBEDDING.md)** - Detaillierte Setup-Anleitung für beide Modi
- **[Shopware 6.7 Upgrade](SHOPWARE_67_UPGRADE.md)** - Migration von älteren Versionen
- **[OpenAI Direct Setup](SETUP_DIRECT_OPENAI.md)** - Setup nur für direkte OpenAI Integration

## 🤝 Contributing

1. **Fork** das Repository
2. **Branch** erstellen: `git checkout -b feature/neue-funktion`
3. **Änderungen** committen: `git commit -am 'Neue Funktion hinzugefügt'`
4. **Push** zum Branch: `git push origin feature/neue-funktion`
5. **Pull Request** erstellen

### Development Guidelines
- PHP 8.1+ Syntax verwenden
- PSR-12 Coding Standards befolgen
- Unit Tests für neue Features schreiben
- Dokumentation aktualisieren

## 📄 License

MIT License - siehe [LICENSE](LICENSE) für Details.

## ✨ Credits

- **Entwickelt von**: [M. Haasler](mailto:info@mhaasler.de)
- **OpenAI Integration**: Basiert auf `text-embedding-ada-002` Modell
- **MySQL Vector Support**: Nutzt MySQL 8.0+ native VECTOR Datentypen

## 🆘 Support

- **Issues**: [GitHub Issues](https://github.com/your-repo/issues)
- **Dokumentation**: Siehe `docs/` Verzeichnis
- **E-Mail**: info@mhaasler.de

---

**⭐ Wenn dir dieses Plugin gefällt, gib ihm einen Stern!**

**🚀 Semantic Search für Shopware - Powered by AI** 