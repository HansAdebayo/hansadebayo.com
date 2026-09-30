# hans-adebayo-site

Landing page de Hans Adebayo. HTML + CSS purs, aucun JavaScript, aucune ressource externe.

## Sécurité
- Aucun script : rien à injecter, rien à exécuter.
- Content-Security-Policy stricte : seuls le CSS et les images du site sont chargés.
- Aucune police, aucun tracker ni CDN externe.
- Liens externes en `rel="noopener noreferrer"`.
- `_headers` : en-têtes de sécurité appliqués automatiquement sur Netlify ou Cloudflare Pages
  (GitHub Pages ne lit pas ce fichier ; la CSP en balise meta reste active).
- Ne jamais committer de secrets (`.env` est ignoré).

## Ajouter ta photo
1. Mets `photo.jpg` dans `assets/` (carrée, ~400 × 400 px).
2. Dans `index.html`, remplace la ligne `<div class="photo photo--vide" ...>` par la balise `<img>` indiquée en commentaire juste au-dessus.

## Mettre en ligne (GitHub Pages)
```bash
cd ~/Desktop/hans-adebayo-site
git init && git add . && git commit -m "Première version"
git branch -M main
git remote add origin git@github.com:<ton-compte>/hans-adebayo-site.git
git push -u origin main
```
Puis sur GitHub : Settings > Pages > Branch `main` / root.
