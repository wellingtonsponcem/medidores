const fs = require('fs');

async function test() {
  const envTxt = fs.readFileSync('.env', 'utf-8');
  const apiKey = envTxt.split('\n').find(l => l.startsWith('GEMINI_API_KEY=')).split('=')[1].trim();

  try {
    const res = await fetch(`https://generativelanguage.googleapis.com/v1beta/models?key=${apiKey}`);
    const data = await res.json();
    console.log("AVAILABLE MODELS:", data.models.map(m => m.name).join('\n'));
  } catch(e) { console.log(e); }
}

test();
