const GROUPS = ["Peito","Costas","Pernas","Ombros","Braços"];

const ELOS = [
  { name:"BRONZE",   min:0,   color:"#cd7f32", icon:"🥉" },
  { name:"PRATA",    min:40,  color:"#c0c0d8", icon:"🥈" },
  { name:"OURO",     min:70,  color:"#ffd700", icon:"🥇" },
  { name:"PLATINA",  min:100, color:"#7ef9ff", icon:"💎" },
  { name:"DIAMANTE", min:140, color:"#b967ff", icon:"👑" },
];

const state = {};
GROUPS.forEach(g => state[g] = 0);

const calc1RM = (load, reps) => load * (1 + reps / 30);

const getElo = (value) => {
  let elo = ELOS[0];
  for (const e of ELOS) if (value >= e.min) elo = e;
  return elo;
};

const els = {
  form: document.getElementById("loadForm"),
  groupSelect: document.getElementById("muscleGroup"),
  eloGroupName: document.getElementById("eloGroupName"),
  eloBadge: document.getElementById("eloBadge"),
  eloIcon: document.getElementById("eloIcon"),
  eloName: document.getElementById("eloName"),
  eloValue: document.getElementById("eloValue"),
};

document.getElementById("year").textContent = new Date().getFullYear();

function updateEloDisplay(group){
  const value = state[group];
  const elo = getElo(value);
  els.eloGroupName.textContent = group.toUpperCase();
  els.eloIcon.textContent = elo.icon;
  els.eloName.textContent = elo.name;
  els.eloName.style.color = elo.color;
  els.eloValue.textContent = value.toFixed(1);
  els.eloBadge.style.borderColor = elo.color;
  els.eloBadge.style.color = elo.color;
  els.eloBadge.style.boxShadow = `0 0 20px ${elo.color}`;
  els.eloBadge.classList.add("pulse");
  setTimeout(() => els.eloBadge.classList.remove("pulse"), 900);
}

const ctx = document.getElementById("radarChart").getContext("2d");
const radarChart = new Chart(ctx, {
  type: "radar",
  data: {
    labels: GROUPS,
    datasets: [{
      label: "1RM Estimado (kg)",
      data: GROUPS.map(g => state[g]),
      backgroundColor: "rgba(0,255,242,0.15)",
      borderColor: "#00fff2",
      borderWidth: 2,
      pointBackgroundColor: "#ff00c8",
      pointBorderColor: "#0a0a12",
      pointRadius: 5,
      pointHoverRadius: 7,
    }]
  },
  options: {
    responsive: true,
    animation: { duration: 800, easing: "easeOutQuart" },
    scales: {
      r: {
        angleLines: { color: "rgba(255,255,255,0.08)" },
        grid: { color: "rgba(255,255,255,0.08)" },
        pointLabels: { color: "#e8e8f5", font: { size: 13, family: "Rajdhani", weight: 600 } },
        ticks: { display: false, backdropColor: "transparent" },
        suggestedMin: 0,
        suggestedMax: 160,
      }
    },
    plugins: {
      legend: { labels: { color: "#8a8aa3" } }
    }
  }
});

els.groupSelect.addEventListener("change", (e) => updateEloDisplay(e.target.value));

els.form.addEventListener("submit", (e) => {
  e.preventDefault();
  const group = els.groupSelect.value;
  const load = parseFloat(document.getElementById("load").value);
  const reps = parseFloat(document.getElementById("reps").value);
  if (!load || !reps) return;

  state[group] = calc1RM(load, reps);
  updateEloDisplay(group);

  radarChart.data.datasets[0].data = GROUPS.map(g => state[g]);
  radarChart.update();

  els.form.reset();
  els.groupSelect.value = group;
});

updateEloDisplay(els.groupSelect.value);
