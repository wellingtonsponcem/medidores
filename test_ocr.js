const fs = require('fs');

async function testOcr() {
  const f1 = fs.readFileSync('C:/Users/Wellington/.gemini/antigravity/brain/e1bb381c-0a8b-4e4a-b0b8-fda5328901b9/.user_uploaded/media_1791150988288.png').toString('base64');
  const f2 = fs.readFileSync('C:/Users/Wellington/.gemini/antigravity/brain/e1bb381c-0a8b-4e4a-b0b8-fda5328901b9/.user_uploaded/media_1791150975543.jpg').toString('base64');
  const f3 = fs.readFileSync('C:/Users/Wellington/.gemini/antigravity/brain/e1bb381c-0a8b-4e4a-b0b8-fda5328901b9/.user_uploaded/media_1791150980164.jpg').toString('base64');
  const f4 = fs.readFileSync('C:/Users/Wellington/.gemini/antigravity/brain/e1bb381c-0a8b-4e4a-b0b8-fda5328901b9/.user_uploaded/media_1791150983970.jpg').toString('base64');

  const prompt_text = `Você é um especialista em OCR e extração de dados estruturados. Sua tarefa é processar imagens de faturas de água (CESAN) e fotos de hidrômetros para fins de gestão financeira.

### REGRAS DE EXTRAÇÃO:

1. **Fatura CESAN:**
   - Localize o valor no campo "TOTAL A PAGAR" (ex: R$ 560,18).
   - Localize o número no campo "LEITURA ATUAL" dentro da seção de informações de leitura (ex: 1806).

2. **Hidrômetros (Fotos):**
   - Identifique apenas os dígitos nos roletes de cor **preta**. Ignore completamente os dígitos vermelhos.
   - Retorne o número inteiro formado pelos dígitos pretos.

### INFORMAÇÃO DOS ARQUIVOS:
- Imagem 1: Fatura CESAN
- Imagem 2: Hidrômetro H1
- Imagem 3: Hidrômetro H2
- Imagem 4: Hidrômetro H3

### FORMATO DE SAÍDA:
Retorne os dados estritamente em formato JSON:
{
  "fatura": {
    "valor_total": 560.18,
    "leitura_fatura": 1806
  },
  "medidores_individuais": [
    { "identificador": "h1", "leitura_processada": "490" },
    { "identificador": "h2", "leitura_processada": "284" },
    { "identificador": "h3", "leitura_processada": "189" }
  ]
}`;

  const payload = {
    contents: [{
      parts: [
        { text: prompt_text },
        { inlineData: { mimeType: 'image/png', data: f1 } },
        { inlineData: { mimeType: 'image/jpeg', data: f2 } },
        { inlineData: { mimeType: 'image/jpeg', data: f3 } },
        { inlineData: { mimeType: 'image/jpeg', data: f4 } }
      ]
    }],
    generationConfig: { responseMimeType: 'application/json' }
  };

  try {
    const res = await fetch('https://wedistinto.com/medidores/api.php?action=invoke_function&name=ocr-gemini', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    const json = await res.json();
    console.log("RESPONSE HTTP STATUS:", res.status);
    console.log("RESPONSE JSON:", JSON.stringify(json, null, 2));
  } catch(e) {
    console.error("ERRO FETCH:", e);
  }
}

testOcr();
