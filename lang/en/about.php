<?php

/*
 * Copy for the /about page and its enquiry form. All marketing text lives
 * here, so edit this file rather than the views.
 */

return [
    'title' => 'About EDOS',

    'who' => [
        'heading' => 'Who we are',
        'body' => [
            'EDOS is an engineering team. On this stream we build software live, mistakes and all, and Applied Research Equity is the app that runs the show: the question queue, the votes and the overlays.',
            'We stream because building in public keeps us honest. You see how the work actually gets done, not a polished demo of it.',
        ],
    ],

    'orkestera' => [
        'heading' => 'What Orkestera is',
        'body' => [
            'Orkestera is the agentic workflow suite we build at EDOS. You give it a goal, it turns that into tasks for AI agents to plan, carry out and score, and it records every step so you can see what happened and why.',
            'Code changes come back as pull requests for a person to review. Nothing goes straight to your main branch.',
        ],
        'link_label' => 'Orkestera on GitHub',
        'link_url' => 'https://github.com/EDOS-Engineering/Orkestera',
    ],

    'work' => [
        'heading' => 'Work with EDOS',
        'body' => [
            'EDOS Professional Services helps teams build and run software like the things you see on stream, from agentic workflows to Laravel apps.',
            'If you have something in mind, tell us a little about it below and we will reply by email. There is no mailing list.',
        ],
    ],

    'form' => [
        'name' => 'Name',
        'email' => 'Email',
        'company' => 'Company (optional)',
        'message' => 'What are you working on?',
        'consent' => 'EDOS may store these details and contact me by email about this enquiry.',
        'consent_note' => 'We store what you type here and which stream link brought you, and we use no third-party trackers.',
        'submit' => 'Send enquiry',
        'thanks_heading' => 'Thanks, we got it.',
        'thanks_body' => 'Someone from EDOS will reply by email.',
        'throttled' => 'Too many enquiries from your connection. Please try again in :minutes minutes.',
    ],
];
