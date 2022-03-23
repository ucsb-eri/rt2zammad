# rt2zammad
RT Export + Zammad Import

## Description
The code was originally developed by zammad community user fgaspar (Federico Gasparrini) and was found via the following thread
https://community.zammad.org/t/re-migration-from-rt/8660 - I swear this worked originally, but doesn't seem to now.  NOTE: I think you have to be signed in on the community site to be able to actually see the ticket.
https://community.zammad.org/t/re-merge-tickets-with-api/8939 - this is another link with fgaspar content that still existed as of 2022-03-23

The original code had very few comments, since, like this reworking, it was going to be used once and then abandoned.
Because of that I had to do some reverse engineering to figure some of the stuff out.  The original code consisted of three separate scripts that did not utilize classes or subroutines.

I found a few structural issues with the code, which I resolved in various fashions.  Any comments in the code about issues/structure found/resolved are not meant in any negative fashion.  I would not have tackled this project if I did not have fgaspar's scripts to start from :-)  And while I refactored big sections of the code, much of the core code remains intact.

### Notes:
This code will probably never be completely polished as once we are able to complete our export/import "successfully enough", it will get set to the side and likely never used again.

We used a separate import account in Zammad to run the api against.  Some quick points:
* Zammad should be put into import_mode to allow dates to get punched in correctly
  * ```zammad run rails c -r "Setting.set('import_mode',true)"```
* Account used to import into api should have agent (to allow Requestor/Customer to be set) and admin roles (pretty sure, still testing)

## Modifications from Original Code
This is definitely not an exhaustive list of changes, but provides a general feel for the scope of the changes.
* Moved configuration information into a separate PHP file.  
  * An example of this file is at config.php.example, a copy should be made to config.php and then modified to fit the use case
* Combined the three separate scripts into a single utility that takes a subcommand argument
  * create-tickets  - create tickets
    * Really the only portion we used in the end.
  * assign-customer - believe this assigns customers/requestors after import
    * may not be needed if requestors are attached correctly during create-tickets (which seemed to happen for us)
    * seems to require a zammad db to be in same dbms as RT db (only users table required) to provide a mapping of user/customer ids from '''rt''' to '''zammad'''
  * merge-tickets
    * Looked like this was designed to merge tickets after an initial import using create-tickets
    * Did not work for us, api always complained
    * The --merge option (see below) helps to even avoid the need for it
    * Was needed because original/default operation uses RT Ticket.id which uses pre-merge ticket ids.
      * --merge operation utilizes Ticket.EffectiveId so that all transactions for the EffectiveId get processed into the already merged tickets id.
* Added --verbose flag (not really implemented yet though)
* Added --debug flag that provides extra debugging output
* Added --test flag that does a sorta dry run so that the destination API does not get any requests
* Added --tickets= flag that allows the user to process only certain tickets
  * --tickets=ticket#           -- specifies one ticket
  * --tickets=ticket#:ticket#   -- specifies a range of tickets
  * --tickets='<ticket#'        -- specifies all tickets less than # (pretty sure quotes are requires to escape redirection in the shell)
  * --tickets='>ticket#'        -- specifies all tickets greater than # (pretty sure quotes are requires to escape redirection in the shell)
* Added --dedup flag that prevents duplication of articles during import if there are multiple "Requestors" associated with a given ticket.
  * because of the joins coupled with grouping of that transaction query, transaction lines would get duplicated for each email and would end up creating duplicate articles on the zammad side.
* Added --merge flag that changes the transaction queries to use RT Ticket.EffectiveId instead of Ticket.id when building the queries for processing transactions
  * This flag effectively causes tickets merged in RT to be imported directly as merged tickets
  * This gets around some issues with the ticket_merge api calls which I was never able to sort out
* Restructured tranaction processing
  * New method loops over tickets honoring the --tickets option and passes in a single ticket id to a method that loops over transactions.
* Added in some new fields that seemed to fix the time issue mentioned in some of fgaspar's threads
  * added updated_at field helped with those
  * added last_contact_at and last_contact_agent_at fields, but pretty sure those were not honored/used.
* Fixed a case where data array was not initialized correctly and data was carried over from a previous iteration of the loop
* Fixed the new ticket data, where timestamps for the ticket portion were not being honored.
  * added the created_at and updated_at fields to the initial data['article'] values to resolve that
* Modified the prefix setup so it was not hardwired to 43,000,000,000 or whatever it was.
  * if not specified, it keeps ticket numbers the same as RT.  That will work for new zammad installs, but likely unsuitable for existing installs where data is being merged in
  * prefix is determined by getting a list of ALL tickets to sort out how long the longest ticket number is
  * from there an offset value is created and just added to the RT ticket number to get the zammad ticket number
    * super useful for running multiple imports during testing as I could perform multiple runs with different prefixes before having to reset/clear the zammad db.
* Modifed the log setup and output to allow logging to be easily redirected to a file (via the --log=logfile argument)
* Added --drop flag to drop the rt2zammad table that is created to map RT ticket ids to zammad ticket ids (the database ones seen in URL, not the ticket display id)
* reworked the db call that gets the RT to Zammad ticket id mapping.
  * Original invocation used a select that could potentially return multiple lines (if that table existed for separate import runs of the same tickets)
    * First row was grabbed as the answer, if doing multiple imports, it would attempt to add new articles to a previously imported copy of the same ticket as opposed to the must recent (likely current) imported version
    * Added ORDER BY DESC to the query so that the initial row will be for the most recent (ie: current) import


### Other items:
* Had to add sql command to create the rt_zammad table that is used by the code...  Assumed the code is just two integers as they are ticket ids.

### Things I wanted to do, but will not have the time to address:
* modularize the individual api json building sections
  * The create, reply (and others) sections are very similar and the code is duplicated in each of those areas making the giant switch statement hard to follow.
  * Those sections each use a php array to build the structure for the conversion to json
    * most use an array named "data", but a few use an array named "article".  Pretty sure "article" could be easily renamed to "data" and things would work, but return on time invested vs risk was not high enough for me to push that through.  "article" is used as a key in other locations, so it's usage is not unique to that situation.
* Wanted to do more restructuring of the transaction query loop, but after adding in the merge, the logic and changes were going to require more time to verify that the changes would work correctly.  This relates to the issue mentioned in the --dedup option mentioned above.
  * At issue is that RT and Zammad deal with correspondence differently.
    * Zammad seems to store them as a list (comma separated???)
    * RT stores Requestors/CCs as individual entries
      * So the join/grouping creates "separate" transactions to process
      * In essence, one needs to create an appropriately separated list of users to associate with a single transaction

## Examples
```
php rt2zammad.php --debug --verbose --test --tickets='<20000' create-tickets
php rt2zammad.php --debug --verbose --test --tickets=10:1000 create-tickets
php ./rt2zammad.php --prefix=$pref --log=./log-$(date +'%Y%m%d-%H%M')-$pref.log --debug --dedup --tickets=23900:23999 --drop --merge create-tickets

```

### clear all tickets and reset zammad for a new import
```
zammad run rails r 'Ticket.destroy_all'
zammad run rails r 'OnlineNotification.destroy_all'
zammad run rails r 'ActivityStream.destroy_all'
zammad run rails r 'RecentView.destroy_all'
zammad run rails r 'History.destroy_all'
```

This can also be done by running:
```
zammad run rails console
```
And then issuing the commands in single quotes from the rails commands above
