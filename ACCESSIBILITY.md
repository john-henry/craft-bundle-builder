# Accessibility statement for Bundle Builder

Bundle Builder is a plugin for Craft CMS 5. This statement sets out how accessible it is, what I know is wrong with it, and how to tell me about anything else.

## What this covers

This statement covers Bundle Builder 1.3.0:

- the bundles index, where it adds the New bundle button and its menu of bundle types
- the bundle types screens, including the read-only view shown when admin changes are off
- the bundle editor on the product page, including the Bundles field
- the "Change bundle variants" dialog on the order edit page

It doesn't cover:

- Craft's own control panel, which Craft reports on at https://craftcms.com/accessibility
- your site's own templates and content

## The standard I'm working to

WCAG 2.2 level AA. Bundle Builder adds to the Craft control panel, which is an authoring tool, so I also follow ATAG 2.0 Part A where it applies to the parts Bundle Builder adds. Craft itself works to the same standards and publishes its own reports at https://craftcms.com/accessibility, but those reports don't cover plugins.

## How far it meets it

I believe it meets WCAG 2.2 AA. The checks for this version found three issues in 1.2.0, and this version fixes them: the switch that turns a bundle type on for a site had no accessible name, the button that opens the New bundle menu had none either, and that menu put links inside list options. It hasn't been tested with assistive technology yet, though, so I'm not claiming full conformance until it has.

## How it was checked

Last checked in November 2026:

- An automated scan with axe-core, in Chromium, of each screen listed above, against the WCAG 2.0, 2.1 and 2.2 level A and AA rules. No violations were left after the fixes in this version.
- Keyboard checks of the New bundle menu, the remove button on a component row, and the "Change bundle variants" dialog: focus moves into the dialog, Escape closes it and focus goes back to the button that opened it.
- The "Change bundle variants" dialog at a 320 pixel wide window, where it fits without scrolling sideways.
- A review in September 2026 of every control panel template, script and stylesheet against WCAG 2.2 AA, including the WAI-ARIA Authoring Practices for any custom widgets, and contrast ratios worked out from the actual colours in the CSS.
- Not done yet: testing with screen readers (NVDA, JAWS and VoiceOver), zoom at 400% on the other screens, or speech input. This statement will be updated once that's done.

## Known issues

None known right now. The drag handle and remove button on a component row are smaller than 24 by 24 pixels; they have enough space around them to meet the target size rule, and rows can also be moved from the row's actions menu. If you come across a problem, please tell me (see below).

## Tell me about a problem

If something in Bundle Builder is hard or impossible for you to use, open an issue at https://github.com/john-henry/craft-bundle-builder/issues and put "Accessibility" in the title. Say which screen you were on, what you were trying to do, and which browser and assistive technology you use, if any. I aim to reply within five working days.

## Last reviewed

24 November 2026, for version 1.3.0.
