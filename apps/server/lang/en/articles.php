<?php

/*
 * The account articles pages -- the help-centre answers a visitor can find in
 * the widget without asking.
 *
 * Both views share this catalogue because they share vocabulary: the
 * published/draft state appears on each, and two words for one state would be
 * the first thing a reader noticed.
 *
 * What is NOT here, deliberately: the article's own title, body and slug, and
 * every block in the preview. That is the account's content, not this
 * product's copy, and it renders identically in every language because it is
 * the same text.
 */

return [
    'title' => 'Articles',
    'subtitle' => 'Answers a visitor can find without asking.',
    'back_to_account' => 'Back to account',
    'back_to_articles' => 'Back to articles',

    'flash' => [
        'created' => 'Article created as a draft.',
        'saved' => 'Article saved.',
        'published' => 'Article published. Visitors can find it now.',
        'unpublished' => 'Article unpublished. Visitors can no longer find it.',
        'deleted' => 'Article deleted.',
    ],

    'validation' => [
        'title' => 'Give the article a title.',
        'body' => 'Write something a visitor can read.',
    ],

    'state' => [
        'published' => 'Published',
        'draft' => 'Draft',
    ],

    'write' => [
        'heading' => 'Write an article',
        'lede' => 'Saved as a draft. Nothing reaches a visitor until you publish it.',
        'title_label' => 'Title',
        'title_placeholder' => 'How refunds work',
        'body_label' => 'Body',
        // Kept as a worked example rather than instructions: the markup shown
        // is what the reader will type, so it stays in the source syntax while
        // the words around it translate.
        'body_placeholder' => "## Refunds\n\nWe refund within **14 days**. Email [support](mailto:help@example.com).",
        'markup_hint' => 'Headings with :headings, bullets with :bullets, links as :links, emphasis with :emphasis. Anything else is read as ordinary text.',
        // The BRACKETS and asterisks are syntax and stay. The words inside them
        // are not -- they tell the reader what goes there, and a German writer
        // reading `[words]` learns less than one reading `[Wörter]`.
        'markup_links' => '[words](https://…)',
        'markup_emphasis' => '**bold**',
        'submit' => 'Create draft',
    ],

    'list' => [
        'heading' => 'Everything written so far',
        'lede' => 'Drafts first, because they are the ones still wanting work.',
        'count' => '{1} :count article|[2,*] :count articles',
        'search_label' => 'Search',
        'search_placeholder' => 'By title',
        'search_submit' => 'Search articles',
        'column_article' => 'Article',
        'column_state' => 'State',
        'column_edited' => 'Last edited',
        'no_match' => 'No article title matches “:search”.',
        'empty' => 'Nothing written yet. The first article is usually the question your desk answers most.',
    ],

    'detail' => [
        'subtitle' => 'Edit the answer, then decide who can see it.',
        'visibility_heading' => 'Who can see this',
        'visible' => 'Visitors can find this in the widget when they search.',
        'hidden' => 'A draft. Only this account can see it.',
        'slug' => 'Referred to as :slug, which stays the same if you retitle it — so a link an agent already sent keeps working.',
        'publish' => 'Publish',
        'unpublish' => 'Unpublish',
        'edit_heading' => 'The answer',
        'save' => 'Save article',
        'preview_heading' => 'What a visitor sees',
        'preview_lede' => 'Built from the same blocks the widget builds, so this is the article rather than an impression of it.',
        'delete_heading' => 'Delete',
        'delete_lede' => 'Removes the article outright. Unpublishing is the reversible option.',
        'delete' => 'Delete this article',
    ],
];
