PDF Metadata
==================

The PDF Metadata module extends the default functionality of Drupal's core File module by adding the ability to use entity based tokens in PDF metadata attributes which will be added to an existing PDF document.

In simple terms, PDF Metadata allows you to automatically set metadata to uploaded files (PDFs) using token based replacement patterns to enhance SEO.


Features
--------

* Configurable metadata attributes that use entity tokens to generate metadata and apply it to an existing PDF at a field level.
* Supports entity reference to entities with files to allow for more customized token formats.
* Support for the following attributes:
    * Title
    * Author
    * Subject
    * Keywords
* Utilizes Exiftool and supports up to PDF Version 1.7.

File Field - Configuration
-------------------

Once installed, PDF Metadata needs to be configured for each file field you wish to use. Settings can be found on the settings form of any supported file based field.

    Structure > Media > Job Document > Manage fields > File
    http://example.com/admin/structure/media/manage/job_document/fields/field_document

You will need to enable PDF Metadata on that individual field, and apply a token format that utilizes the current entities fields.  This will trigger the regenerating of the PDF with the metadata provided from the token when that entity is updated.

Entity References - Configuration
-------------------

PDF Metadata can also be used at an entity reference field level.  This allows for the usage of parent entity tokens (Example: node using media entities).

    Structure > Content types > Jobs > Manage fields > Job File
    http://example.com/admin/structure/types/manage/jobs/fields/field_job_file

We will need to enable PDF Metadata on this entity reference field and also apply a token format that can use the current nodes fields. Next, we need to go to the entity that it references and enable which file fields should have their PDF metadata added.

    Structure > Media > Job Document > Manage fields > File
    http://example.com/admin/structure/media/manage/job_document/fields/field_document

For this, we will want to check the 'Enable PDF Metadata for Referencing Entities' checkbox.  This will disable 'Enable PDF Metadata' because it will cause a conflict when a that entity gets saved.  The next time the original entity gets updated it should automatically generate the new PDF for each enabled file field.

Known Issues
--------------------------
* Currently does not support reactive updates. Will need to use something like 'Resave All' to generate all PDFs for a given content type.
* No validation for having multiple reference entities pointing to the same file field and overwriting metadata for files that are used in multiple entities at the same time.


History and Maintainers
-----------------------

PDF Metadata was written and is maintained by Jordan Barnes.

* https://jordan-barnes.com
