// test.js
const { createClient } = require('@supabase/supabase-js');

const SUPABASE_URL = "https://jmdlraaprcztshkfyeud.supabase.co";
const SUPABASE_KEY = "sb_publishable_2c7MrFAZaCiQzY_EuQuizQ_wTgAzbIC";
const supabase = createClient(SUPABASE_URL, SUPABASE_KEY);

async function run() {
  console.log("Fetching...");
  const { data, error } = await supabase.from('consumo_agua').select('*').order('data_leitura', { ascending: false });
  console.log("Error:", error);
  console.log("Data count:", data ? data.length : 0);
  if(data && data.length > 0) {
      console.log("First row data_leitura:", data[0].data_leitura);
  }
}

run();
