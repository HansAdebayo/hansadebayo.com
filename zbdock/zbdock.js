import * as THREE from './vendor/three.module.min.js';
import { OrbitControls } from './vendor/addons/controls/OrbitControls.js';
import { GLTFLoader } from './vendor/addons/loaders/GLTFLoader.js';

// Couleurs proposées (modifier ici : nom + code couleur)
const COULEURS = [
  { nom: 'Sable', hex: '#d8cbad' },
  { nom: 'Blanc', hex: '#efede6' },
  { nom: 'Noir', hex: '#2a2a2c' },
  { nom: 'Orange', hex: '#e2652f' },
  { nom: 'Noyer', hex: '#6e4b33' },
  { nom: 'Lavande', hex: '#b1a3d4' },
  { nom: 'Bleu', hex: '#4c6ea3' },
  { nom: 'Sauge', hex: '#9db08a' },
];

const pastilles = document.getElementById('pastilles');
const nomCouleur = document.getElementById('nom-couleur');
const selectCouleur = document.getElementById('couleur');
let materiau = null;

function choisir(index) {
  const c = COULEURS[index];
  nomCouleur.textContent = c.nom;
  selectCouleur.value = c.nom;
  pastilles.querySelectorAll('button').forEach((b, i) => b.setAttribute('aria-checked', i === index ? 'true' : 'false'));
  if (materiau) materiau.color.set(c.hex);
}

COULEURS.forEach((c, i) => {
  const b = document.createElement('button');
  b.type = 'button';
  b.className = 'zb-pastille';
  b.setAttribute('role', 'radio');
  b.setAttribute('aria-label', c.nom);
  b.style.setProperty('--c', c.hex);
  b.addEventListener('click', () => choisir(i));
  pastilles.appendChild(b);

  const o = document.createElement('option');
  o.textContent = c.nom;
  selectCouleur.appendChild(o);
});
selectCouleur.addEventListener('change', () => choisir(COULEURS.findIndex(c => c.nom === selectCouleur.value)));

// ---------- Viewer 3D ----------
const conteneur = document.getElementById('viewer');
const chargement = document.getElementById('chargement');

const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
renderer.outputColorSpace = THREE.SRGBColorSpace;
renderer.toneMapping = THREE.ACESFilmicToneMapping;
renderer.shadowMap.enabled = true;
renderer.shadowMap.type = THREE.PCFSoftShadowMap;
conteneur.appendChild(renderer.domElement);

const scene = new THREE.Scene();
const camera = new THREE.PerspectiveCamera(32, 1, 1, 5000);

scene.add(new THREE.HemisphereLight(0xffffff, 0xd8d8d8, 1.6));
const soleil = new THREE.DirectionalLight(0xffffff, 2.2);
soleil.position.set(220, 380, 260);
soleil.castShadow = true;
soleil.shadow.mapSize.set(2048, 2048);
Object.assign(soleil.shadow.camera, { left: -200, right: 200, top: 200, bottom: -200, near: 10, far: 1200 });
soleil.shadow.radius = 6;
scene.add(soleil);
const contre = new THREE.DirectionalLight(0xffffff, 0.8);
contre.position.set(-300, 150, -250);
scene.add(contre);

const sol = new THREE.Mesh(new THREE.PlaneGeometry(2000, 2000), new THREE.ShadowMaterial({ opacity: 0.12 }));
sol.rotation.x = -Math.PI / 2;
sol.receiveShadow = true;
scene.add(sol);

const controls = new OrbitControls(camera, renderer.domElement);
controls.enableDamping = true;
controls.enablePan = false;
controls.autoRotate = true;
controls.autoRotateSpeed = 1.2;
controls.maxPolarAngle = Math.PI * 0.49;
controls.addEventListener('start', () => { controls.autoRotate = false; });

function redimensionner() {
  const { clientWidth: w, clientHeight: h } = conteneur;
  renderer.setSize(w, h, false);
  camera.aspect = w / h;
  camera.updateProjectionMatrix();
}
new ResizeObserver(redimensionner).observe(conteneur);
redimensionner();

materiau = new THREE.MeshStandardMaterial({ color: COULEURS[0].hex, roughness: 0.82, metalness: 0 });
choisir(0);

new GLTFLoader().load('assets/zbdock.glb', (gltf) => {
  const modele = gltf.scene;
  // Le STL est en Z vers le haut : on le remet en Y vers le haut
  modele.rotation.x = -Math.PI / 2;
  modele.traverse((o) => {
    if (o.isMesh) {
      o.geometry.computeVertexNormals();
      o.material = materiau;
      o.castShadow = true;
      o.receiveShadow = true;
    }
  });
  const groupe = new THREE.Group();
  groupe.add(modele);
  const boite = new THREE.Box3().setFromObject(groupe);
  const centre = boite.getCenter(new THREE.Vector3());
  modele.position.sub(new THREE.Vector3(centre.x, boite.min.y, centre.z));
  scene.add(groupe);

  const taille = boite.getSize(new THREE.Vector3()).length();
  controls.target.set(0, (boite.max.y - boite.min.y) * 0.45, 0);
  camera.position.set(taille * 1.25, taille * 0.75, taille * 1.35);
  controls.minDistance = taille * 0.9;
  controls.maxDistance = taille * 3.5;
  controls.update();
  chargement.remove();
}, undefined, () => {
  chargement.textContent = 'Le modèle 3D n’a pas pu se charger.';
});

renderer.setAnimationLoop(() => {
  controls.update();
  renderer.render(scene, camera);
});

// ---------- Formulaire ----------
const form = document.getElementById('form');
const statut = document.getElementById('statut');
const champT = document.getElementById('t');
let ouverture = performance.now();

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  statut.className = 'zb-statut';
  if (!form.checkValidity()) {
    form.reportValidity();
    return;
  }
  // Durée passée sur le formulaire (anti-robots) : calculée sur l'appareil, sans dépendre de l'horloge
  champT.value = String(Math.round(performance.now() - ouverture));
  const bouton = form.querySelector('button[type="submit"]');
  bouton.disabled = true;
  statut.textContent = 'Envoi en cours…';
  try {
    const rep = await fetch(form.action, {
      method: 'POST',
      body: new FormData(form),
      headers: { Accept: 'application/json' },
    });
    const data = await rep.json().catch(() => ({}));
    if (!rep.ok || !data.ok) throw new Error(data.erreur || 'Envoi impossible');
    form.reset();
    ouverture = performance.now();
    choisir(0);
    statut.classList.add('zb-statut--ok');
    statut.textContent = 'Merci ! Votre ZB Dock est réservé. On vous recontacte très vite.';
  } catch (err) {
    statut.classList.add('zb-statut--ko');
    statut.textContent = (err && err.message ? err.message : 'Envoi impossible') + '. Réessayez ou écrivez à contact.hansadebayo@gmail.com.';
  } finally {
    bouton.disabled = false;
  }
});

// ---------- Quantité et total ----------
const PRIX_CENTIMES = 2599;
const champQuantite = document.getElementById('quantite');
const total = document.getElementById('total');
const formatEuro = (c) => (c / 100).toLocaleString('fr-FR', { minimumFractionDigits: 2 }) + ' €';
function majTotal() {
  let q = parseInt(champQuantite.value, 10);
  if (!Number.isFinite(q)) q = 1;
  q = Math.min(10, Math.max(1, q));
  champQuantite.value = String(q);
  total.textContent = formatEuro(PRIX_CENTIMES * q);
}
document.getElementById('moins').addEventListener('click', () => { champQuantite.value = String(+champQuantite.value - 1); majTotal(); });
document.getElementById('plus').addEventListener('click', () => { champQuantite.value = String(+champQuantite.value + 1); majTotal(); });
champQuantite.addEventListener('change', majTotal);
form.addEventListener('reset', () => setTimeout(majTotal));

// ---------- Barre d'achat collante (mobile) ----------
const collante = document.getElementById('collante');
const zoneAchat = document.getElementById('achat');
if ('IntersectionObserver' in window) {
  new IntersectionObserver(([e]) => {
    // visible seulement quand le bloc d'achat est sorti de l'écran (au-dessus)
    const montrer = !e.isIntersecting && e.boundingClientRect.top < 0;
    collante.classList.toggle('visible', montrer);
  }).observe(zoneAchat);
}
