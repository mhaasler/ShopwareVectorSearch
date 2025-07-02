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

# Setup: Flexible Embedding Service

Diese Anleitung zeigt, wie du das Plugin mit einem eigenen Embedding Service verwendest.

## Voraussetzungen

- Embedding Service (Python Flask App)
- MySQL 5.7+ (alle Versionen unterstützt)
- Shopware 6.5+

## 1. Embedding Service Setup

### Python Environment erstellen
```bash
python -m venv embedding-env
source embedding-env/bin/activate  # Linux/Mac
# oder: embedding-env\Scripts\activate  # Windows

pip install flask sentence-transformers torch
```

### Embedding Service (app.py)
```python
from flask import Flask, request, jsonify
from sentence_transformers import SentenceTransformer
import numpy as np

app = Flask(__name__)

# Load model (you can use different models)
model = SentenceTransformer('all-MiniLM-L6-v2')  # 384 dimensions
# model = SentenceTransformer('all-mpnet-base-v2')  # 768 dimensions

@app.route('/health', methods=['GET'])
def health():
    return jsonify({
        'status': 'healthy',
        'model': model.get_sentence_embedding_dimension(),
        'dimensions': model.get_sentence_embedding_dimension(),
        'ready': True
    })

@app.route('/embed', methods=['POST'])
def embed():
    data = request.get_json()
    
    if not data or 'text' not in data:
        return jsonify({'error': 'Missing text parameter'}), 400
    
    text = data['text']
    
    # Generate embedding
    embedding = model.encode(text)
    
    return jsonify({
        'embedding': embedding.tolist(),
        'model': str(model),
        'dimensions': len(embedding)
    })

@app.route('/embed/batch', methods=['POST'])
def embed_batch():
    data = request.get_json()
    
    if not data or 'texts' not in data:
        return jsonify({'error': 'Missing texts parameter'}), 400
    
    texts = data['texts']
    
    if not isinstance(texts, list):
        return jsonify({'error': 'Texts must be a list'}), 400
    
    # Generate embeddings for all texts
    embeddings = model.encode(texts)
    
    return jsonify({
        'embeddings': [emb.tolist() for emb in embeddings],
        'model': str(model),
        'dimensions': len(embeddings[0]) if len(embeddings) > 0 else 0,
        'count': len(embeddings)
    })

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=8001, debug=False)
```

### Service starten
```bash
python app.py
# Service läuft auf http://localhost:8001
```

## 2. Plugin Konfiguration

### System Config
```php
// config/packages/shopware.yaml
'ShopwareVectorSearch' => [
    'config' => [
        'embeddingMode' => 'embedding_service',
        'embeddingServiceUrl' => 'http://localhost:8001',
        'batchSize' => 100,
        'defaultSimilarityThreshold' => 0.7,
        'maxSearchResults' => 20,
        'embeddingTimeout' => 30
    ]
]
```

### Über Database
```sql
INSERT INTO system_config (id, configuration_key, configuration_value, sales_channel_id, created_at) 
VALUES (
    UNHEX(REPLACE(UUID(), '-', '')),
    'ShopwareVectorSearch.config.embeddingMode',
    '{"_value": "embedding_service"}',
    NULL,
    NOW()
);

INSERT INTO system_config (id, configuration_key, configuration_value, sales_channel_id, created_at) 
VALUES (
    UNHEX(REPLACE(UUID(), '-', '')),
    'ShopwareVectorSearch.config.embeddingServiceUrl',
    '{"_value": "http://localhost:8001"}',
    NULL,
    NOW()
);
```

## 3. Service-Verbindung testen

```bash
# Health Check
curl http://localhost:8001/health

# Einzelnes Embedding
curl -X POST http://localhost:8001/embed \
  -H "Content-Type: application/json" \
  -d '{"text": "Gaming Laptop"}'

# Batch Embedding
curl -X POST http://localhost:8001/embed/batch \
  -H "Content-Type: application/json" \
  -d '{"texts": ["Gaming Laptop", "Office Chair", "Wireless Mouse"]}'
```

## 4. Produkte indexieren

```bash
# Alle Produkte indexieren
bin/console shopware:vector-search:index

# Status prüfen
bin/console shopware:vector-search:status
```

## 5. Suche testen

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

## 6. Health Check

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
      "mode": "embedding_service",
      "url": "http://localhost:8001"
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

## Embedding Service als systemd Service

### Service-Datei erstellen
```bash
sudo nano /etc/systemd/system/embedding-service.service
```

```ini
[Unit]
Description=Embedding Service for Shopware Vector Search
After=network.target

[Service]
Type=simple
User=www-data
WorkingDirectory=/path/to/embedding-service
Environment=PATH=/path/to/embedding-env/bin
ExecStart=/path/to/embedding-env/bin/python app.py
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
```

### Service aktivieren
```bash
sudo systemctl daemon-reload
sudo systemctl enable embedding-service
sudo systemctl start embedding-service
sudo systemctl status embedding-service
```

## Docker Setup

### Dockerfile
```dockerfile
FROM python:3.9-slim

WORKDIR /app

COPY requirements.txt .
RUN pip install -r requirements.txt

COPY app.py .

EXPOSE 8001

CMD ["python", "app.py"]
```

### requirements.txt
```
flask==2.3.3
sentence-transformers==2.2.2
torch==2.0.1
```

### Docker Run
```bash
docker build -t embedding-service .
docker run -d -p 8001:8001 --name embedding-service embedding-service
```

## Performance-Optimierung

### Model-Wahl
```python
# Schnell, weniger genau (384 dim)
model = SentenceTransformer('all-MiniLM-L6-v2')

# Langsamer, genauer (768 dim)  
model = SentenceTransformer('all-mpnet-base-v2')

# Mehrsprachig (768 dim)
model = SentenceTransformer('paraphrase-multilingual-mpnet-base-v2')
```

### GPU-Support
```python
# CUDA-Support aktivieren
import torch
device = 'cuda' if torch.cuda.is_available() else 'cpu'
model = SentenceTransformer('all-MiniLM-L6-v2', device=device)
```

### Batch-Processing
```python
# Größere Batches für bessere GPU-Auslastung
@app.route('/embed/batch', methods=['POST'])
def embed_batch():
    # ... 
    embeddings = model.encode(texts, batch_size=32)  # GPU batch size
```

## Troubleshooting

### Service nicht erreichbar
```bash
# Service Status prüfen
curl http://localhost:8001/health

# Plugin Status prüfen
bin/console shopware:vector-search:status

# Firewall/Ports prüfen
sudo netstat -tlnp | grep 8001
```

### Out of Memory
```python
# Kleinere Batch-Größe
embeddings = model.encode(texts, batch_size=8)

# Model auf CPU
model = SentenceTransformer('all-MiniLM-L6-v2', device='cpu')
```

### Embedding-Dimensionen
```bash
# Plugin zeigt erkannte Dimensionen
bin/console shopware:vector-search:debug

# Service prüfen
curl http://localhost:8001/health
```

### Performance Issues
```bash
# MySQL Version prüfen
bin/console shopware:vector-search:debug

# Kleinere Batch-Größe im Plugin
bin/console shopware:vector-search:index --batch-size=20
```

## Monitoring

### Service-Logs
```bash
# systemd Service
sudo journalctl -u embedding-service -f

# Docker
docker logs -f embedding-service
```

### Performance-Monitoring
```python
import time
import logging

@app.route('/embed', methods=['POST'])
def embed():
    start_time = time.time()
    # ... embedding logic ...
    end_time = time.time()
    
    logging.info(f"Embedding took {end_time - start_time:.3f}s")
    return jsonify(result)
```

### Health-Checks
```bash
# Regelmäßiger Plugin-Status
bin/console shopware:vector-search:status --short

# Service-Health via API
curl "https://your-shop.com/vector-search/health"
``` 