=== Ollama AI Chat ===
Contributors: prose
Tags: chat, ai, ollama, assistant
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Modern AI chat widget for WordPress powered by a local Ollama API.

== Description ==

Ollama AI Chat adds a ChatGPT-style floating chat widget to your WordPress site. It connects to your local Ollama instance via the WordPress REST API, keeping your API URL secure on the server.

**Features:**

* Floating chat widget (bottom-right)
* Shortcode: `[ollama_ai_chat]`
* Gutenberg block
* Admin settings page (Settings → Ollama AI Chat)
* Markdown rendering with syntax highlighting
* Dark mode support
* Conversation history (localStorage + database)
* Streaming-ready architecture
* Role-based access control
* Rate limiting

== Installation ==

1. Upload the `ollama-ai-chat` folder to `/wp-content/plugins/`
2. Run `npm install && npm run build` inside the plugin directory (vanilla JS + Tailwind; no React)
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Go to **Settings → Ollama AI Chat** and configure your Ollama URL and model
5. Ensure Ollama is running on your host

== Ollama Setup ==

Pull the default model:

```
ollama pull qwen2.5-coder:7b
```

Start Ollama (if not already running):

```
ollama serve
```

== Docker Note ==

When WordPress runs inside Docker, `localhost` refers to the container, not your host machine. Use:

`http://host.docker.internal:11434/api/chat`

as the Ollama Base URL in plugin settings.

== Usage ==

**Floating widget:** Enabled by default on all front-end pages (can be disabled in settings).

**Shortcode:**

`[ollama_ai_chat]`

Attributes:
* `title` - Chat window title
* `height` - Panel height (e.g. `500px`)
* `theme` - `light`, `dark`, or `auto`
* `model` - Override model name
* `layout` - `widget` or `inline`

**Gutenberg:** Add the "Ollama AI Chat" block from the Widgets category.

== Frequently Asked Questions ==

= Do guests need to log in? =

Yes. Only logged-in users with allowed roles can use the chat (configurable in settings).

= Does the browser connect directly to Ollama? =

No. All requests go through WordPress REST API (`/wp-json/ollama-ai/v1/chat`), which proxies to Ollama server-side.

== Changelog ==

= 1.0.0 =
* Initial release
