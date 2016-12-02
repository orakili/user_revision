The Drupal 8 version of User Revision was created by Alexey Savchuk (devpreview)
https://www.drupal.org/u/devpreview

1. This module does NOT support being uninstalled.  Once you enable it, you are
stuck with it.

2. Make a backup before installing.  Old user entities are destroyed and
replaced with user revision entities.  All data including fields is copied back
in, but this has only been tested with up to about one hundred users, and is
likely to fail with large amounts of data.

3. You will need to re-enable all user-based views after installation.  They'll
continue to work fine after enabling them again (including core's admin/people
view).