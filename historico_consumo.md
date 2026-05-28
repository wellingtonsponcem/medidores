# Histórico de Consumos 

Este documento contém o histórico extraído da API do Google Sheets para Água e Energia, facilitando a migração para um banco de dados relacional.

---

## 1. Consumo de Água

### Dados Calculados (Período a Período)

Nas tabelas abaixo, apresentamos a **leitura aferida** no respectivo mês e o **consumo calculado** em m³ (a diferença entre a leitura atual e a do mês anterior). 
*Lembrando que o consumo do Hidrômetro 1 é calculado descontando a leitura do Hidrômetro 2 (conforme lógica encontrada no arquivo app.js).*

| Data | Leitura Cesan | Consumo Cesan | Leitura H1 | Consumo H1 (m³) | Leitura H2 | Consumo H2 (m³) | Leitura H3 | Consumo H3 (m³) | Consumo Serviço | Valor da Fatura (R$) | Valor/m³ (R$) |
|---|---|---|---|---|---|---|---|---|---|---|---|
| **30/06/2025** | 1348 | *-* | 265 | *-* | 116 | *-* | 117 | *-* | *-* | R$ 285,80 | *-* |
| **30/07/2025** | 1366 | 18 | 273 | 0 | 124 | 8 | 120 | 3 | 7 | R$ 288,60 | R$ 16,03 |
| **27/08/2025** | 1387 | 21 | 282 | 0 | 133 | 9 | 124 | 4 | 8 | R$ 383,14 | R$ 18,24 |
| **26/09/2025** | 1414 | 27 | 290 | 2 | 139 | 6 | 127 | 3 | 16 | R$ 517,86 | R$ 19,18 |
| **30/10/2025** | 1436 | 22 | 300 | 3 | 146 | 7 | 132 | 5 | 7 | R$ 405,57 | R$ 18,44 |
| **30/11/2025** | 1469 | 33 | 317 | 4 | 159 | 13 | 135 | 3 | 13 | R$ 680,92 | R$ 20,63 |
| **02/01/2026** | 1500 | 31 | 330 | 6 | 166 | 7 | 139 | 4 | 14 | R$ 450,84 | R$ 14,54 |
| **03/02/2026** | 1529 | 29 | 342 | 5 | 173 | 7 | 143 | 4 | 13 | R$ 422,28 | R$ 14,56 |
| **25/02/2026** | 1551 | 22 | 351 | 4 | 178 | 5 | 146 | 3 | 10 | R$ 327,68 | R$ 14,89 |

### Estrutura JSON Bruta (Água):
```json
[
  {"id":"gs_1759279002103_9zqa","data":"2025-06-30T03:00:00.000Z","leituraCesan":1348,"leituraH1":265,"leituraH2":116,"leituraH3":117,"valorFaturaTotal":285.8},
  {"id":"gs_1759279017244_wfbv","data":"2025-07-30T03:00:00.000Z","leituraCesan":1366,"leituraH1":273,"leituraH2":124,"leituraH3":120,"valorFaturaTotal":288.6},
  {"id":"gs_1759279022780_8p19","data":"2025-08-27T03:00:00.000Z","leituraCesan":1387,"leituraH1":282,"leituraH2":133,"leituraH3":124,"valorFaturaTotal":383.14},
  {"id":"gs_1759284987388_yejy","data":"2025-09-26T03:00:00.000Z","leituraCesan":1414,"leituraH1":290,"leituraH2":139,"leituraH3":127,"valorFaturaTotal":517.86},
  {"id":"gs_1761865333177_gm9i","data":"2025-10-30T03:00:00.000Z","leituraCesan":1436,"leituraH1":300,"leituraH2":146,"leituraH3":132,"valorFaturaTotal":405.57},
  {"id":"gs_1764527791716_qrol","data":"2025-11-30T03:00:00.000Z","leituraCesan":1469,"leituraH1":317,"leituraH2":159,"leituraH3":135,"valorFaturaTotal":680.92},
  {"id":"gs_1767401308357_hwzf","data":"2026-01-02T03:00:00.000Z","leituraCesan":1500,"leituraH1":330,"leituraH2":166,"leituraH3":139,"valorFaturaTotal":450.84},
  {"id":"gs_1770144947396_vuoo","data":"2026-02-03T03:00:00.000Z","leituraCesan":1529,"leituraH1":342,"leituraH2":173,"leituraH3":143,"valorFaturaTotal":422.28},
  {"id":"gs_1772057377236_mujx","data":"2026-02-25T03:00:00.000Z","leituraCesan":1551,"leituraH1":351,"leituraH2":178,"leituraH3":146,"valorFaturaTotal":327.68}
]
```

---

## 2. Consumo de Energia

### Dados Calculados (Período a Período)

Nas tabelas abaixo apresentamos o consumo e leituras de energia. O Campo **Consumo Padrão** já é o total em kWh aferido no relógio medidor (leituraPadrao no JSON). A **Casa 2** utiliza um relógio interno, cujo consumo é obtido pela diferença da leitura atual para a anterior, e a **Casa 1** absorve o consumo Padrão abatendo a Casa 2 (lógica de `energia.js`).

| Data | Valor da Fatura (R$/Total) | Consumo Padrão (kWh) | Leitura Medidor Interno | Consumo Casa 2 (kWh) | Consumo Casa 1 (kWh) | Valor KWh (R$/kWh) |
|---|---|---|---|---|---|---|
| **06/05/2025** | R$ 76,61 | 67 | 2397 | *-* | 67 | R$ 1,14 |
| **03/06/2025** | R$ 119,05 | 107 | 2489 | 92 | 15 | R$ 1,11 |
| **05/08/2025** | R$ 97,86 | 103 | 2576 | 87 | 16 | R$ 0,95 |
| **04/09/2025** | R$ 147,62 | 118 | 2671 | 95 | 23 | R$ 1,25 |
| **06/10/2025** | R$ 231,71 | 188 | 2763 | 92 | 96 | R$ 1,23 |
| **18/11/2025** | R$ 224,29 | 186 | 2838 | 75 | 111 | R$ 1,21 |
| **16/12/2025** | R$ 315,10 | 270 | 3025 | 187 | 83 | R$ 1,17 |
| **05/01/2026** | R$ 451,18 | 401 | 3214 | 189 | 212 | R$ 1,13 |
| **03/02/2026** | R$ 392,03 | 342 | 3365 | 151 | 191 | R$ 1,15 |
| **07/03/2026** | R$ 322,51 | 287 | 3512 | 147 | 140 | R$ 1,12 |

### Estrutura JSON Bruta (Energia):
```json
[
  {"id":"energy_1759326129035_k0u2","data":"2025-05-06T03:00:00.000Z","valorFaturaTotal":76.61,"leituraPadrao":67,"leituraInterno":2397},
  {"id":"","data":"2025-06-03T03:00:00.000Z","valorFaturaTotal":119.05,"leituraPadrao":107,"leituraInterno":2489},
  {"id":"","data":"2025-08-05T03:00:00.000Z","valorFaturaTotal":97.86,"leituraPadrao":103,"leituraInterno":2576},
  {"id":"","data":"2025-09-04T03:00:00.000Z","valorFaturaTotal":147.62,"leituraPadrao":118,"leituraInterno":2671},
  {"id":"energy_1759761151409_eeie","data":"2025-10-06T03:00:00.000Z","valorFaturaTotal":231.71,"leituraPadrao":188,"leituraInterno":2763},
  {"id":"energy_1762719512190_a1bc","data":"2025-11-18T03:00:00.000Z","valorFaturaTotal":224.29,"leituraPadrao":186,"leituraInterno":2838},
  {"id":"energy_1764928698984_qlbl","data":"2025-12-16T03:00:00.000Z","valorFaturaTotal":315.1,"leituraPadrao":270,"leituraInterno":3025},
  {"id":"energy_1767703841193_7zz4","data":"2026-01-05T03:00:00.000Z","valorFaturaTotal":451.18,"leituraPadrao":401,"leituraInterno":3214},
  {"id":"energy_1770144650140_ptq3","data":"2026-02-03T03:00:00.000Z","valorFaturaTotal":392.03,"leituraPadrao":342,"leituraInterno":3365},
  {"id":"energy_1772909509394_ras7","data":"2026-03-07T03:00:00.000Z","valorFaturaTotal":322.51,"leituraPadrao":287,"leituraInterno":3512}
]
```
