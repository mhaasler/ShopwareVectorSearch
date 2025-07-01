# Shopware Vector Search - Direkte OpenAI Integration

## Installation

1. **Dependencies installieren:**
```bash
cd ShopwareVectorSearch/
composer install
```

2. **Plugin in Shopware installieren:**
```bash
# Plugin aktivieren
bin/console plugin:install --activate ShopwareVectorSearch

# Cache leeren  
bin/console cache:clear
```

3. **OpenAI API Key konfigurieren:**
- Gehe zu **Einstellungen → System → Plugins → Vector Search → Konfiguration**
- Trage deinen OpenAI API Key ein (beginnt mit `sk-...`)
- Speichere die Konfiguration

## Konfiguration

### Wichtige Einstellungen:

| Einstellung | Beschreibung | Standard |
|-------------|--------------|----------|
| **OpenAI API Key** | Dein OpenAI API Schlüssel | - |
| **Vector Search aktivieren** | Hauptschalter für die Funktion | `true` |
| **Batch-Größe** | Produkte pro Indexierungsvorgang | `100` |
| **Ähnlichkeitsschwelle** | Minimale Ähnlichkeit für Ergebnisse | `0.7` |
| **Max. Suchergebnisse** | Maximale Anzahl Ergebnisse | `20` |
| **OpenAI Timeout** | Timeout für API-Anfragen (Sek.) | `30` |

### OpenAI API Key erhalten:

1. Registriere dich bei [OpenAI](https://platform.openai.com/)
2. Gehe zu **API Keys** 
3. Erstelle einen neuen API Key
4. Kopiere den Key (beginnt mit `sk-...`)

## Verwendung

### 1. Produkte indexieren:

```bash
# Alle Produkte für Vector Search indexieren
bin/console shopware:vector-search:index
```

### 2. API Endpoints nutzen:

**Vector Search:**
```bash
POST /api/vector-search/search
{
    "query": "rotes Kleid",
    "limit": 10,
    "threshold": 0.7
}
```

**Storefront API:**
```bash
POST /store-api/vector-search  
{
    "query": "smartphone",
    "limit": 5
}
```

## Kosten

### OpenAI Preise (Stand 2024):
- **text-embedding-ada-002**: $0.0001 / 1K tokens
- **Beispiel**: 1000 Produktbeschreibungen ≈ $0.05-0.20

### Kostenoptimierung:
- Nutze Batch-Verarbeitung (bereits implementiert)
- Indexiere nur bei Produktänderungen (Auto-Reindex)
- Überwache die Usage in deinem OpenAI Dashboard

## Vorteile vs. Embedding Service

### ✅ Direkte OpenAI Integration:
- Keine zusätzliche Infrastruktur
- Weniger Komplexität  
- Direkter Support durch OpenAI
- Automatische Updates des Modells

### ❌ Nachteile:
- Direkte Abhängigkeit von OpenAI
- API Rate Limits
- Weniger Flexibilität bei Modellwechsel
- Kosten direkt bei OpenAI

## Troubleshooting

### Häufige Probleme:

**1. "OpenAI API Key is not configured"**
- Prüfe die Plugin-Konfiguration
- API Key muss mit `sk-` beginnen

**2. "OpenAI API Rate Limit"**  
- Reduziere Batch-Größe
- Warte und versuche erneut
- Prüfe dein OpenAI Limit

**3. "Using JSON fallback search"**
- MySQL Version < 8.0.28 erkannt
- Automatischer Fallback auf JSON-Speicherung (funktioniert vollständig!)
- Prüfe Details mit: `bin/console shopware:vector-search:debug`

### Logs prüfen:
```bash
# Shopware Logs
tail -f var/log/dev.log | grep VectorSearch

# Debug-Logging aktivieren in Plugin-Konfiguration
```

## Performance

### Empfohlene MySQL-Konfiguration:
```sql
-- Für bessere Vector-Performance
SET GLOBAL innodb_buffer_pool_size = 1073741824; -- 1GB
SET GLOBAL max_connections = 200;
```

### Monitoring:
- Überwache OpenAI API Usage
- Prüfe Embedding-Tabellengröße regelmäßig
- Überwache Response Times

## Migration vom Embedding Service

Falls du vorher den separaten Embedding Service verwendet hast:

1. **Backup erstellen:**
```bash
mysqldump shopware mh_product_embeddings > embeddings_backup.sql
```

2. **Neue Version installieren** (wie oben beschrieben)

3. **API Key konfigurieren**

4. **Embeddings bleiben erhalten** - kein Re-Indexing erforderlich

Die bestehenden Embeddings sind kompatibel, da beide Systeme das gleiche OpenAI Modell verwenden. 