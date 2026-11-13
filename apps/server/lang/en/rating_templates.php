<?php

return [
    'document_title' => 'Rating template',
    'title' => 'Rating template for :site',
    'subtitle' => 'Write the questions visitors are asked when a conversation closes. Editing the draft changes nothing until you publish it.',
    'restricted' => 'Only an account admin can edit this site’s rating template.',

    'flash' => [
        'draft_saved' => 'Draft saved. Visitors still see the version that is published.',
        'published' => 'Published as version :version. Choose it under the site’s rating settings to start asking it.',
    ],

    'draft' => [
        'heading' => 'Draft',
        'lede' => 'Your work in progress. It starts from the built-in questions, so you edit something that already works.',
        'save' => 'Save draft',
    ],

    'scale' => [
        'legend' => 'Answer scale',
        'three' => 'Three answers: good, ok, bad',
        'five' => 'Five stars',
        'help' => 'Which answers count as positive is fixed by the scale — good on three answers, four and five stars on five — so the figure means the same thing on every site and in every version. Answers on different scales are always reported apart.',
    ],

    'locales' => [
        'en' => 'English',
        'de' => 'German',
        'it' => 'Italian',
    ],

    'questions' => [
        'caption' => 'Questions',
        'question' => 'Question',
        'asked' => 'Asked',
        'always' => 'Always',
        'ask' => 'Ask: :question',
        'wording' => ':question in :language',
        'overall' => 'Overall experience',
        'agent' => 'The agent who helped',
        'comment' => 'Comment',
        'help' => 'Write each question in English; a visitor reading another language sees English wherever that language is empty. The agent question needs {name} where the agent’s name goes, and is only asked when exactly one person replied to the visitor.',
    ],

    'labels' => [
        'caption' => 'Answer labels',
        'answer' => 'Answer',
        'wording' => ':answer in :language',
        'help' => 'Only the labels for the chosen scale are shown to visitors. Every one in use needs English.',
        'choices' => [
            'good' => 'Good',
            'ok' => 'Ok',
            'bad' => 'Bad',
            '1' => 'One star',
            '2' => 'Two stars',
            '3' => 'Three stars',
            '4' => 'Four stars',
            '5' => 'Five stars',
            'not_applicable' => 'Not applicable (agent question)',
        ],
    ],

    'preview' => [
        'heading' => 'Preview',
        'lede' => 'The saved draft as a visitor reading English sees it.',
        'fallback' => 'Visitors whose language has no wording here see English.',
    ],

    'publish' => [
        'heading' => 'Publish',
        'lede' => 'Publishing turns the saved draft into a new, numbered version. A published version is never changed afterwards, so every answer keeps the exact question it was given to.',
        'button' => 'Publish the saved draft as version :version',
        'nothing' => 'Save a draft first; there is nothing to publish yet.',
        'select' => 'Publishing does not start asking it. Choose your own template under :settings on the site’s settings page, and switch back to a built-in question at any time without losing a version or an answer.',
    ],

    'versions' => [
        'caption' => 'Published versions',
        'empty' => 'Nothing has been published yet.',
        'version' => 'Version',
        'number' => 'Version :version',
        'question' => 'Overall question (English)',
        'scale' => 'Scale',
        'published' => 'Published',
    ],
];
