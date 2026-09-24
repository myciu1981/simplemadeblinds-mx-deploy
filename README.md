# simplemadeblinds.mx — pliki wdrożeniowe

To repo **nie zawiera źródeł strony**. Leżą w nim gotowe, zbudowane pliki, które
cPanel kopiuje pod `simplemadeblinds.mx`. Źródła są w prywatnym repo
[`Mexico-Website`](https://github.com/myciu1981/Mexico-Website)
(Vite + React, ES/PL/EN), lokalnie w `C:\Users\USER\Projects\smb-mx-website`.

## Po co osobne repo

cPanelowy Git Version Control potrafi tylko sklonować repo i przekopiować pliki
według `.cpanel.yml`. Nie uruchomi `npm` ani `vite`. Do tego repo ze źródłami jest
prywatne, a bez shell access cPanel nie skonfiguruje klucza SSH — nie sklonowałby go.

Stąd podział: źródła zostają prywatne, a to repo jest publiczne i trzyma wyłącznie
to, co i tak jest publiczne, bo stanowi zawartość strony.

## Wdrożenie

```powershell
.\zbuduj.ps1
git add -A
git commit -m "opis zmiany"
git push
```

Potem w cPanelu: **Git™ Version Control → Manage → Pull or Deploy**:

1. **Update from Remote** — ściąga commit z GitHuba
2. **Deploy HEAD Commit** — wykonuje `.cpanel.yml`

Oba kroki, w tej kolejności. Samo `git push` niczego nie wdraża.

## Na co uważać

**Build to trzy kroki**: klient, SSR (`--ssr src/entry-server.tsx`) i
`script/prerender.mjs`. Prerender wstawia treść do HTML i nadaje każdej trasie
własny tytuł, opis i adres kanoniczny. Bez niego strona jest pusta dla robotów,
które nie wykonują JavaScriptu (ChatGPT, Claude, Perplexity).

**cPanel przy wdrożeniu tylko kopiuje, nigdy nie usuwa.** Dlatego `.cpanel.yml`
kasuje przed kopiowaniem `assets/`, `gallery/`, `docs/` i `api/`. Pliki luzem
w korzeniu są nadpisywane; gdyby któryś zniknął z repo, trzeba go skasować na
serwerze ręcznie.

**`.htaccess` musi być w `site/`.** Niesie HTTPS, `www` → bez www, `/api/leads` →
`api/leads.php`, `/products` → `products.html`, prawdziwe 404, cache
i `Require all granted`, bez którego nethero oddaje 403 crawlerom AI.
Plik źródłowy jest w `client/public/.htaccess` w repo ze źródłami.

**Formularz** (`api/leads.php`) zapisuje każde zgłoszenie do
`/home/myciu/smb-leads.jsonl`, zanim cokolwiek wyśle — to zastępuje bazę
Postgres z Replita. Klucz Resend leży w `/home/myciu/smb-config.php`
(`<?php return ['resend_api_key' => 're_...'];`), poza katalogiem publicznym
i poza repo. Bez klucza powiadomienie idzie lokalnym `mail()` na info@,
a autoodpowiedź do klienta nie wychodzi. Każdy błąd Resend trafia do
`/home/myciu/smb-resend-errors.log` — także wtedy, gdy `mail()` dostarczył
powiadomienie, bo inaczej brak autoodpowiedzi byłby niewidoczny.

**Zmiany treści robi się w repo ze źródłami.** Ręczna edycja plików w `site/`
zostanie skasowana przy najbliższym `zbuduj.ps1`.

## Co gdzie leży

| | |
|---|---|
| `site/` | zbudowana strona, dokładna kopia `dist/public` ze źródeł |
| `.cpanel.yml` | co i dokąd cPanel kopiuje przy wdrożeniu |
| `zbuduj.ps1` | build ze źródeł + przełożenie do `site/` |
| `.gitattributes` | `* -text`, żeby Git nie ruszał końców linii |

Document root na serwerze: `/home/myciu/public_html/simplemadeblinds.mx`
