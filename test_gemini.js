const fs = require('fs');

async function test() {
  const envTxt = fs.readFileSync('.env', 'utf-8');
  const apiKey = envTxt.split('\n').find(l => l.startsWith('GEMINI_API_KEY=')).split('=')[1].trim();

  // Let's test providing base64.
  const payload = {
    contents: [{
      parts: [
        { text: "oi" }
      ]
    }],
    generationConfig: {
      responseMimeType: "application/json"
    }
  };

  try {
    const res = await fetch(`https://generativelanguage.googleapis.com/v1/models/gemini-pro:generateContent?key=${apiKey}`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    console.log("Status:", res.status);
    console.log(await res.text());
  } catch(e) { console.log(e); }
}

test();
