<?php

declare(strict_types=1);

// The email an agent's reply is sent to a visitor as. Every line here is read
// by a CUSTOMER, never an agent, and in the language they were reading the
// widget in -- not the install's and not the agent's. Plain text: no markup.
return [
    'reply' => [
        // Used when the conversation has no subject of its own, which is most
        // widget conversations. The mailer adds "Re: " in front.
        'subject_fallback' => 'Your support request',
        'signature' => ':site support',
        // Only when the site has an address that receives mail: a reply to
        // this email then threads back onto the same conversation.
        'reply_by_email' => 'Reply to this email and it will reach the same conversation.',
        // When it does not, a reply goes to the install's sender address and
        // reaches no conversation, so the visitor is sent back to the chat.
        'continue_at' => 'Replies to this email do not reach this conversation. To write back, open the chat at :domain.',
        'continue_in_chat' => 'Replies to this email do not reach this conversation. To write back, open the chat on our website.',
    ],
];
