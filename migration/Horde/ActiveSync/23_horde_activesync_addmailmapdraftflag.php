<?php
class HordeActiveSyncAddMailMapDraftFlag extends Horde_Db_Migration_Base
{
    public function up()
    {
        $this->addColumn(
            'horde_activesync_mailmap',
            'sync_draft',
            'boolean');
    }

    public function down()
    {
        $this->removeColumn('horde_activesync_mailmap', 'sync_draft');
    }

}
