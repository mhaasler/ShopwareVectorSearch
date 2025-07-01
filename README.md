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

## 📋 Requirements

### System Requirements
- **PHP**: 8.1 oder höher
- **Shopware**: 6.6.x oder 6.7.x
- **MySQL**: 8.0.28+ (empfohlen für Vector Support)
- **Memory**: Mindestens 512MB für PHP

### Optional für bessere Performance
- **MySQL Vector Support**: MySQL 8.0.28+ für native VECTOR Datentypen
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
```

### 4. Testen

```bash
# API Test
curl -X POST http://your-shop.com/api/_action/vector-search \
  -H "Content-Type: application/json" \
  -d '{"query": "rotes Kleid", "limit": 5}'

# Storefront API Test
curl -X POST http://your-shop.com/store-api/vector-search \
  -H "Content-Type: application/json" \
  -d '{"query": "smartphone", "limit": 3}'
```

## 📡 API Documentation

### Admin API Endpoints

#### Produkte indexieren
```http
POST /api/_action/vector-search/index
Authorization: Bearer {admin-token}

Response:
{
  "success": true,
  "data": {
    "indexed": 150,
    "errors": 0,
    "total_products": 150,
    "message": "Successfully indexed 150 products"
  }
}
```

#### Vector Search
```http
POST /api/_action/vector-search/search
Content-Type: application/json
Authorization: Bearer {admin-token}

{
  "query": "rotes Kleid",
  "limit": 10,
  "threshold": 0.7
}

Response:
{
  "success": true,
  "data": {
    "query": "rotes Kleid",
    "results": [
      {
        "product_id": "...",
        "similarity": 0.85,
        "distance": 0.15,
        "content": "Rotes Abendkleid..."
      }
    ],
    "count": 5,
    "limit": 10,
    "threshold": 0.7
  }
}
```

#### Status prüfen
```http
GET /api/_action/vector-search/status
Authorization: Bearer {admin-token}

Response:
{
  "success": true,
  "data": {
    "embedding_service": {
      "status": "healthy",
      "url": "http://localhost:8001",
      "model": "text-embedding-ada-002",
      "dimensions": 1536,
      "ready": true
    },
    "database": {
      "status": "available",
      "total_embeddings": 150,
      "last_indexed": "2024-01-15T10:30:00Z"
    },
    "plugin_version": "1.0.0"
  }
}
```

### Storefront API Endpoints

#### Public Vector Search
```http
POST /store-api/vector-search
Content-Type: application/json
sw-access-key: {storefront-access-key}

{
  "query": "gaming laptop",
  "limit": 5
}

Response:
{
  "success": true,
  "data": {
    "query": "gaming laptop",
    "results": [
      {
        "product_id": "...",
        "similarity": 0.92,
        "content": "Gaming Laptop with RTX..."
      }
    ],
    "count": 3
  }
}
```

## 🔧 Console Commands

### Vector Search Indexierung
```bash
# Alle Produkte indexieren
bin/console shopware:vector-search:index

# Mit zusätzlichen Optionen
bin/console shopware:vector-search:index --force-reindex --batch-size=50
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

## 🚀 Migration von 6.4 zu 6.7

Siehe [SHOPWARE_67_UPGRADE.md](SHOPWARE_67_UPGRADE.md) für detaillierte Upgrade-Anweisungen.

**Kurz-Version:**
```bash
# 1. Code aktualisieren
git pull origin main

# 2. Plugin updaten
bin/console plugin:update ShopwareVectorSearch

# 3. Migrations ausführen
bin/console database:migrate --all

# 4. Embeddings neu generieren (wichtig!)
bin/console shopware:vector-search:index --force-reindex
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