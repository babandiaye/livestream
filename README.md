# mod_livestream — Webinaire LiveStream UN-CHK

Module d'activité Moodle permettant de créer des sessions de webconférence
intégrées à Moodle via la plateforme **LiveStream UN-CHK** (backend Next.js /
LiveKit).

- **Composant** : `mod_livestream`
- **Version** : 1.2.2-security-audit (`2026040500`)
- **Maturité** : BETA
- **Compatibilité** : Moodle 4.5+ (`requires = 2024100700`)

> Ce README décrit les fonctionnalités **actuellement disponibles** dans le
> plugin. Il est mis à jour à chaque intégration d'une nouvelle fonctionnalité.

---

## Présentation

Le plugin agit comme un **client léger** : il ne gère pas la visioconférence
lui-même mais délègue toute la logique (salles, jetons LiveKit, enregistrements)
à la plateforme LiveStream via une API REST/JSON. Chaque activité Moodle est
reliée à une salle distante par les champs `roomid` / `roomname`.

---

## Fonctionnalités actuelles

### Création de salle
À l'ajout de l'activité dans un cours, le plugin crée automatiquement une salle
distante sur la plateforme LiveStream (`createRoom`) et enregistre son `roomid`
et son `roomname`. L'opération est transactionnelle : en cas d'échec API,
l'instance n'est pas créée (`lib.php`).

### Enrôlement automatique des participants
Si l'option `autoenroll` est activée, tous les utilisateurs du cours disposant
de la capacité `mod/livestream:view` sont enrôlés dans la salle (envoi des
emails à l'API via `enrollUsers`).

### Démarrage de session (modérateur)
Les utilisateurs disposant de `mod/livestream:moderate` voient le bouton
**Démarrer la session**, qui obtient une URL d'hôte signée et redirige vers la
salle LiveStream.

### Participation à une session en direct
Lorsque la salle est `LIVE`, le bouton **Rejoindre la session** est proposé.
Le plugin récupère une URL de participation et redirige l'utilisateur (avec
`returnUrl` de retour vers Moodle).

### Statut en temps réel
La page d'activité affiche l'état de la salle interrogé via l'API :
- 🟢 **En direct** (`LIVE`) — indicateur clignotant
- ⏳ **Session planifiée** (`SCHEDULED`)
- ⏹ **Session terminée** (`ENDED`)

### Enregistrements
La page liste les enregistrements de la salle (nom, date, durée) avec :
- un lecteur vidéo intégré (lecture inline sans quitter Moodle) ;
- la suppression d'un enregistrement pour les modérateurs (`deleteRecording`).

### Liste des activités du cours
`index.php` affiche l'ensemble des activités LiveStream d'un cours.

### Journalisation d'audit
Quatre événements Moodle sont déclenchés et consultables dans les journaux :

| Événement | Déclencheur |
|---|---|
| `room_created` | Création d'une salle |
| `session_started` | Démarrage d'une session (modérateur) |
| `session_joined` | Participation à une session |
| `recording_deleted` | Suppression d'un enregistrement |

---

## Architecture

```
Moodle (mod_livestream)  ──REST/JSON──>  Plateforme LiveStream (Next.js / LiveKit)
   view.php / lib.php                        /api/moodle/rooms
   classes/api.php (cURL)                    /api/moodle/join | /start | /enroll
                                             /api/moodle/rooms/{id}/status
                                             /api/moodle/rooms/{id}/recordings
                                             /api/moodle/recordings/{id}
```

Authentification des appels : en-tête `X-Api-Key` (secret partagé Moodle ↔
LiveStream).

### Fichiers principaux

| Fichier | Rôle |
|---|---|
| `lib.php` | Cycle de vie de l'instance (add/update/delete), enrôlement |
| `classes/api.php` | Client cURL vers l'API LiveStream (validation des entrées) |
| `view.php` | Page d'activité : statut, boutons, lecteur d'enregistrements |
| `index.php` | Liste des activités du cours |
| `mod_form.php` | Formulaire de création/édition |
| `settings.php` | Réglages d'administration |
| `db/install.xml` | Table `livestream` |
| `db/access.php` | Capacités (rôles) |
| `db/caches.php` | Cache de rate limiting |
| `classes/event/` | Événements d'audit |

---

## Prérequis

- Moodle 4.5 ou supérieur
- Une instance LiveStream UN-CHK accessible en HTTPS
- La clé API partagée (`MOODLE_API_KEY` dans le `.env` de LiveStream)

---

## Installation

1. Copier le dossier dans `mod/livestream` de votre installation Moodle :
   ```
   <moodle>/mod/livestream/
   ```
2. Se connecter en administrateur et suivre l'assistant de mise à niveau
   (*Administration du site → Notifications*).
3. Configurer le plugin (voir ci-dessous).

---

## Configuration

*Administration du site → Plugins → Modules d'activité → LiveStream UN-CHK*

| Réglage | Clé | Défaut | Description |
|---|---|---|---|
| URL de la plateforme | `apiurl` | `https://preprod-webinaire.unchk.sn` | URL de l'instance LiveStream |
| Clé API | `apikey` | *(vide)* | Secret partagé (`MOODLE_API_KEY`) |
| Timeout (s) | `apitimeout` | `30` | Délai max des appels API |
| Enrôlement automatique | `autoenroll` | activé | Enrôle les participants du cours dans la salle |

> La clé API est **obligatoire** : sans elle, l'instanciation du client lève
> `apinotconfigured`.

---

## Capacités (rôles)

| Capacité | Niveau | Rôles par défaut |
|---|---|---|
| `mod/livestream:addinstance` | Cours | editingteacher, manager |
| `mod/livestream:view` | Module | student, teacher, editingteacher, manager |
| `mod/livestream:moderate` | Module | teacher, editingteacher, manager |

> L'accès invité (`guest`) est volontairement retiré : aucune participation non
> authentifiée n'est autorisée.

---

## Sécurité

Le plugin a fait l'objet d'un audit de sécurité (repères `V01`–`V10`) :

- **V01 / V06** — validation que l'URL de redirection renvoyée par l'API
  appartient bien au domaine autorisé (et ses sous-domaines).
- **V02** — protection CSRF (`require_sesskey`) sur join / start / delete.
- **V05** — rate limiting : 5 tentatives/minute/utilisateur (cache session).
- **V08** — sanitisation des noms d'utilisateur, validation des emails et des
  identifiants avant envoi à l'API.
- **V09** — journalisation d'audit via les événements Moodle.
- **V10** — messages d'erreur génériques (pas de fuite de détails techniques).
- Vérification stricte du certificat TLS sur tous les appels cURL.

---

## Historique des versions

| Version | Faits marquants |
|---|---|
| 1.2.2-security-audit | Audit de sécurité (V01–V10), événements d'audit, rate limiting |

> À compléter à chaque nouvelle fonctionnalité intégrée.

---

## Licence

GNU GPL v3 ou ultérieure, conformément à Moodle.
