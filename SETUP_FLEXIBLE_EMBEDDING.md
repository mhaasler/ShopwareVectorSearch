# Shopware Vector Search - Flexible Embedding Setup

## Übersicht

Dieses Plugin unterstützt **zwei Modi** für die Embedding-Generierung:

1. **🌐 External Embedding Service** - Nutzt einen separaten Embedding-Server
2. **🤖 Direct OpenAI API** - Direkte Verbindung zur OpenAI API

## Installation

### 1. Dependencies installieren
```bash
cd ShopwareVectorSearch/
composer install
```

### 2. Plugin in Shopware installieren
```bash
# Plugin aktivieren
bin/console plugin:install --activate ShopwareVectorSearch

# Cache leeren  
bin/console cache:clear
```

### 3. Embedding-Modus konfigurieren

Gehe zu **Einstellungen → System → Plugins → Vector Search → Konfiguration**

## Modus 1: External Embedding Service 🌐

### Wann verwenden?
- Du betreibst bereits einen Embedding-Server
- Du möchtest verschiedene Embedding-Modelle testen
- Du brauchst mehr Kontrolle über die Embedding-Generierung
- Du möchtest Kosten durch lokale Modelle sparen

### Setup:

1. **Embedding Mode**: `External Embedding Service` auswählen
2. **Embedding Service URL**: URL deines Services eingeben (z.B. `http://localhost:8001`)
3. **OpenAI API Key**: Kann leer bleiben

### Embedding Server starten:
```bash
# OpenAI Embedding Service (aus deinem Projekt)
python openai_embedding_service.py

# Oder anderen Embedding Server nutzen
```

### Testen:
```bash
curl -X POST http://localhost:8001/embed \
  -H "Content-Type: application/json" \
  -d '{"text": "Test Product"}'
```

## Modus 2: Direct OpenAI API 🤖

### Wann verwenden?
- Du möchtest keine zusätzliche Infrastruktur verwalten
- Du brauchst maximale Zuverlässigkeit (OpenAI SLA)
- Du bevorzugst einfache Konfiguration
- Du hast ein OpenAI Budget verfügbar

### Setup:

1. **Embedding Mode**: `Direct OpenAI API` auswählen
2. **OpenAI API Key**: Deinen OpenAI API Key eingeben (beginnt mit `sk-...`)
3. **Embedding Service URL**: Kann leer bleiben

### OpenAI API Key erhalten:
1. Registriere dich bei [OpenAI](https://platform.openai.com/)
2. Gehe zu **API Keys** 
3. Erstelle einen neuen API Key
4. Kopiere den Key (beginnt mit `sk-...`)
5. Stelle sicher, dass du Guthaben auf deinem OpenAI Account hast

## Allgemeine Konfiguration

| Einstellung | Beschreibung | Standard | Modi |
|-------------|--------------|----------|------|
| **Embedding Mode** | Auswahl zwischen Service/Direct | `External Service` | Beide |
| **Embedding Service URL** | URL des externen Services | `http://localhost:8001` | Service Mode |
| **OpenAI API Key** | OpenAI API Schlüssel | - | Direct Mode |
| **Vector Search aktivieren** | Hauptschalter | `true` | Beide |
| **Batch-Größe** | Produkte pro Batch | `100` | Beide |
| **Ähnlichkeitsschwelle** | Minimale Ähnlichkeit | `0.7` | Beide |
| **Max. Suchergebnisse** | Maximale Ergebnisse | `20` | Beide |
| **Request Timeout** | Timeout in Sekunden | `30` | Beide |
| **Debug-Logging** | Detaillierte Logs | `false` | Beide |
| **Auto-Reindex** | Auto-Neuindexierung | `true` | Beide |

## Verwendung

### 1. Produkte indexieren
```bash
# Alle Produkte für Vector Search indexieren
bin/console shopware:vector-search:index
```

### 2. API Endpoints nutzen

**Vector Search:**
```bash
POST /api/_action/vector-search
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

## Kostenvergleich

### External Embedding Service 🌐
**Kosten:**
- Server-Hosting (z.B. €5-20/Monat für VPS)
- Eigene OpenAI API Calls (falls openai_embedding_service.py)
- Oder kostenlos bei lokalen Modellen

**Vorteile:**
- Volle Kontrolle über Modelle
- Potentiell kostengünstiger bei vielen Anfragen
- Offline-fähig mit lokalen Modellen
- Flexibilität bei Modellwechsel

### Direct OpenAI API 🤖
**Kosten:**
- text-embedding-ada-002: $0.0001 / 1K tokens
- Beispiel: 1000 Produktbeschreibungen ≈ $0.05-0.20

**Vorteile:**
- Keine Infrastruktur
- Hochverfügbar (99.9% SLA)
- Automatische Updates
- Einfache Abrechnung

## Monitoring & Troubleshooting

### Logs prüfen
```bash
# Shopware Logs mit Modus-Info
tail -f var/log/dev.log | grep VectorSearch

# Debug-Logging in Plugin-Konfiguration aktivieren
```

### Häufige Probleme

**1. "Embedding service URL is not configured" (Service Mode)**
- Prüfe die Service URL in der Konfiguration
- Teste den Service direkt: `curl http://your-service/health`

**2. "OpenAI API Key is not configured" (Direct Mode)**
- Prüfe den API Key in der Konfiguration
- Key muss mit `sk-` beginnen
- Prüfe OpenAI Account Guthaben

**3. "Vector search is disabled"**
- Aktiviere "Vector Search aktivieren" in der Konfiguration

**4. Unterschiedliche Embedding-Qualität**
- Beide Modi nutzen `text-embedding-ada-002` → gleiche Qualität
- Bei unterschiedlichen Services: Modell-Kompatibilität prüfen

### Performance Monitoring

**Service Mode überwachen:**
```bash
# Service Health Check
curl http://localhost:8001/health

# Response Times messen
time curl -X POST http://localhost:8001/embed -d '{"text":"test"}'
```

**Direct Mode überwachen:**
- OpenAI Dashboard: [platform.openai.com/usage](https://platform.openai.com/usage)
- Rate Limits beachten (3,000 RPM für tier 1)

## Migration zwischen Modi

### Von Service zu Direct:
1. OpenAI API Key in Konfiguration eingeben
2. Embedding Mode auf "Direct OpenAI API" ändern
3. Konfiguration speichern
4. **Kein Re-Indexing nötig** (gleiche Embeddings)

### Von Direct zu Service:
1. Embedding Server starten
2. Service URL in Konfiguration eingeben
3. Embedding Mode auf "External Embedding Service" ändern
4. Konfiguration speichern
5. **Kein Re-Indexing nötig** (gleiche Embeddings)

## Empfehlungen

### Für Development/Testing:
- **External Service** mit lokalem Server
- Kostenlos und flexibel
- Einfaches Debugging

### Für Production (klein):
- **Direct OpenAI API**
- Weniger Infrastruktur
- Zuverlässiger

### Für Production (groß):
- **External Service** mit optimiertem Setup
- Kosteneffizienter bei hohem Volumen
- Mehr Kontrolle über Performance

## Support

Bei Problemen:
1. Debug-Logging aktivieren
2. Logs prüfen (`var/log/dev.log`)
3. Service-spezifische Troubleshooting-Schritte befolgen
4. GitHub Issues erstellen mit Logs und Konfiguration 