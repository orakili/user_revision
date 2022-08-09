User Revision
=============

This Drupal module makes user entities revisionable.

Drupal core issue: https://www.drupal.org/project/drupal/issues/540118

Important notes
---------------

Please make sure you backup your data before enabling or updating this module.

**Install**

The module will update the existing user entities on install to add the revision
fields and tables. This is done via a batch and the number of entities to update
at once can be controlled via the Drupal core `entity_update_batch_size` setting
which defaults to 50.

**Update**

If you are using an old 8.x version of this module, please run `drush updatedb`
or the like to run the post update hooks that try to convert the old fields to
the new ones and update the user entities.

**Uninstall**

Drupal doesn't support making revisionable entities non revisionable. So once
this module is enabled, it cannot be uninstalled.

**Other modules**

This module should be installed after other modules that add base fields to the
user entity type so that they can be made revisionable.

Configuration Options
---------------------

**Revision mode**

This option controls whether revisions are enabled or disabled.

  - Disabled: changes via the UI will override the current revision
  - Required: changes via the UI will always create a new revision
  - Optional: a checkbox is added to the user form to select whether to create a
    revision or not

**Create new revision by default**

With this option checked, a new revision is always created when a non-privileged
user edits their own account. When a user with "administer users" privilege
edits a user account, by default they will create a new revision, but they also
have the option of not creating one.

If this option (and the Always create new revision option) is not checked, then
when a non-privileged user edits their own account, no new revision is created.
When a user with "administer users" privilege edits a user account, by default
they will not create a new revision, but they also have the option of creating
one if they wish.

**Allow account's owner to enter revision log messages**

With this option checked, non-privileged user have the opportunity to enter
a revision log message when they edit their own account, if a new revision
will be created. Users with "administer users" privilege always have the
opportunity to enter a log message when a new revision is created.

**Preserve password revisions**

With this option checked, revisions will store the password at the time of
the revision. Otherwise, the password of the previous revision is emptied when
creating a new revision.

Preserving old passwords, even if they hashed, is a security risk.

If this option is unchecked, after reverting to a previous revision, the user
will have to set a new password.

**Display revision information group initially open**

With this option checked, the "Revision Information" group on the user edit form
will be open when the form is initially displayed.

TODO
----

Maybe this module should replace the default User class with a class
implementing the \Drupal\Core\Entity\RevisionLogInterface to ease retrieving
the revision fields (created, log message etc.).

Credits
-------

The Drupal 8 version of User Revision was initially created by Alexey Savchuk
(devpreview) https://www.drupal.org/u/devpreview
