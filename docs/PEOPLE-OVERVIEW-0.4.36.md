# People overview full-photo previews (0.4.36)

The People overview can switch between face crops and the full photo with its detected face marked. Full photos fit inside larger cards without cropping. The browser remembers the choice; switching preserves multiple selection and requires no additional subject or media API requests. The same switch is available under Filtered faces.

The existing authorized context-preview endpoint supplies the marked image. Recognition, grouping and unknown-person actions are unchanged.

Validation: all 37 People tests passed, including the actual template at 320, 390 and 1360 pixels in both themes, synthetic landscape and portrait previews, existing gallery query parameters, multiple selection, and preference restoration. Build, generated CSS/translations and JavaScript syntax checks passed. No private media was inspected.
