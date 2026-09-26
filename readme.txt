=== NetArz AI — هوش مصنوعی نِت اَرز ===
Contributors: netarz
Tags: ai, live chat, support tickets, chatbot, ai writer
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI live-support chat with human handoff, a support-ticket system and AI writing tools — powered by your NetArz AI API key.

== Description ==

چت پشتیبانی هوشمند، تیکتینگ و ابزارهای نوشتن برای وردپرس، با یک کلید API نِت اَرز.

* Live chat widget whose assistant answers from your own notes and your published posts, pages and WooCommerce products, and hands the conversation to a person when it is not sure.
* Operator inbox in wp-admin with AI reply suggestions, per-conversation AI switch and chat-to-ticket.
* Support tickets for members and guests: departments, priorities, attachments, canned replies, internal notes, auto-close, ratings, WooCommerce "My account" tab, AI drafts or confident auto-answers, AI summaries.
* Writer tools in the block and classic editors: articles, outlines, rewriting, proofreading, translation, excerpts, titles, tags, FAQ, SEO title/description (Yoast, Rank Math, AIOSEO and SEOPress aware), WooCommerce product copy, featured images, alt text, comment replies.
* Credit and usage dashboard for your NetArz account.

= External service =

This plugin sends AI requests to the NetArz AI gateway at https://netarz.ir/api/ai/v1 using the API key you enter. For chat and ticket answers it sends the customer's message and short excerpts of your published content that match it; for writer tools it sends the text you ask it to work on. Terms of service: https://netarz.ir/terms

== Installation ==

1. Upload the plugin to `/wp-content/plugins/netarz-ai` and activate it.
2. Create a project API key at https://netarz.ir/ai (it starts with `sk-ntz-v1-`).
3. Open AI → Settings, paste the key and save.
4. Turn on the chat widget in the "Chat" tab and fill in "Custom knowledge".

== Changelog ==

= 1.0.0 =
* First release.
