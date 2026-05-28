const fs = require('fs');

async function test() {
  const envTxt = fs.readFileSync('.env', 'utf-8');
  const apiKey = envTxt.split('\n').find(l => l.startsWith('GEMINI_API_KEY=')).split('=')[1].trim();

  // payload snake_case
  const payload = {
    contents: [{
      parts: [
        { text: "Diga 123" }
      ]
    }],
    generationConfig: {
      responseMimeType: "application/json"
    }
  };

  try {
    const res = await fetch(`https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=${apiKey}`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    console.log("Status:", res.status);
    console.log(await res.text());
  } catch(e) { console.log(e); }

}

test();
