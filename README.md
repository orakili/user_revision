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


Configuration Options
---------------------

* Display revision information group initially open

With this option checked, the "Revision Information" group on the user edit form
will be open when the form is initially displayed.

* Always create new revision

With this option checked, a new revision is always created when a user account
is edited, even for users with the "administer users" privilege.

* Create new revision by default

With this option checked, a new revision is always created when a non-privileged
user edits their own account. When a user with "administer users" privilege
edits a user account, by default they will create a new revision, but they also
have the option of not creating one.

If this option (and the Always create new revision option) is not checked, then
when a non-privileged user edits their own account, no new revision is created.
When a user with "administer users" privilege edits a user account, by default
they will not create a new revision, but they also have the option of creating
one if they wish.

* Allow ordinary users to enter revision log messages

With this option checked, non-privileged user have the opportunity to enter
a revision log message when they edit their own account, if a new revision
will be created. Users with "administer users" privilege always have the
opportunity to enter a log message when a new revision is created.
