<?php

namespace KBox;

use KBox\Traits\ScopeNullUuid;
use KBox\Traits\LocalizableDateFields;
use Illuminate\Database\Eloquent\Model;
use Dyrynda\Database\Support\GeneratesUuid;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The project concept.
 *
 * @property int $id
 * @property \Ramsey\Uuid\Uuid $uuid the UUID that identify the project
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property string $name
 * @property string $description
 * @property string $avatar
 * @property int $user_id
 * @property int $collection_id
 * @property-read \KBox\Group $collection
 * @property-read \KBox\User $manager
 * @property-read \Klink\DmsMicrosites\Microsite $microsite
 * @property-read \Illuminate\Database\Eloquent\Collection|\KBox\User[] $users
 * @method static \Illuminate\Database\Query\Builder|\KBox\Project managedBy($user)
 * @method static \Illuminate\Database\Query\Builder|\KBox\Project whereAvatar($value)
 * @method static \Illuminate\Database\Query\Builder|\KBox\Project whereCollectionId($value)
 * @method static \Illuminate\Database\Query\Builder|\KBox\Project whereCreatedAt($value)
 * @method static \Illuminate\Database\Query\Builder|\KBox\Project whereDescription($value)
 * @method static \Illuminate\Database\Query\Builder|\KBox\Project whereId($value)
 * @method static \Illuminate\Database\Query\Builder|\KBox\Project whereName($value)
 * @method static \Illuminate\Database\Query\Builder|\KBox\Project whereUpdatedAt($value)
 * @method static \Illuminate\Database\Query\Builder|\KBox\Project whereUserId($value)
 * @mixin \Eloquent
 */
class Project extends Model
{
    use LocalizableDateFields, GeneratesUuid, ScopeNullUuid;
    /*

        $table->bigIncrements('id');
        $table->string('name');
        $table->text('description')->nullable();
        $table->string('avatar')->nullable();

        $table->bigInteger('user_id')->unsigned(); --> project manager
        $table->bigInteger('collection_id')->unsigned(); --> root project collection

        $table->timestamps();

     */

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'projects';
    
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = ['name', 'description', 'user_id', 'collection_id', 'avatar'];

    protected $casts = [
        'uuid' => 'uuid',
    ];
    
    /**
     * The root collection that stores the hierarchy of the project
     */
    public function collection()
    {
        return $this->hasOne(\KBox\Group::class, 'id', 'collection_id');
    }
    
    /**
     * The relation with the user tha manages the project
     */
    public function manager()
    {
        return $this->belongsTo(\KBox\User::class, 'user_id', 'id');
    }
    
    /**
     * The users that partecipates into the Project
     */
    public function users()
    {
        return $this->belongsToMany(\KBox\User::class, 'userprojects', 'project_id', 'user_id');
    }

    public function scopeManagedBy($query, $user)
    {
        return $query->where('user_id', $user);
    }
    
    /**
     * The associated microsite
     */
    public function microsite()
    {
        return $this->hasOne('\Klink\DmsMicrosites\Microsite');
    }
    
    public function getDocumentsCount()
    {
        // if (! $this->collection->hasChildren()) {
        //     return $this->collection->documents()->count();
        // }

        // return $this->collection->getDescendants()->load('documents')->pluck('documents')->collapse()->count() + $this->collection->documents()->count();
        return $this->getDocumentsQuery()->count();
    }

    /**
     * Query that retrieve all documents in this project
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function getDocumentsQuery()
    {
        return $this->collection->withAllDescendants()->
                            has('documents')->
                            leftJoin('document_groups', 'groups.id', '=', 'document_groups.group_id')->
                            leftJoin('document_descriptors', 'document_descriptors.id', '=', 'document_groups.document_id')->
                            distinct()->
                            select('document_descriptors.id');
    }
    
    public function documents()
    {
        return DocumentDescriptor::whereIn('id', $this->getDocumentsQuery());
    }
    
    /**
     * Test if a project is accessible by a user
     *
     * @param Project $project the project to test the accessibility
     * @param User $user the user to use for the testing
     * @return boolean true if the project can be accessed by $user
     */
    public static function isAccessibleBy(Project $project, User $user)
    {
        if ($user->isDMSManager()) {
            return true;
        }

        // TODO: this can be optimized

        $managed = $user->managedProjects()->get(['projects.id'])->pluck('id')->toArray();

        $added_to = $user->projects()->get(['projects.id'])->pluck('id')->toArray();

        return in_array($project->id, $managed) || in_array($project->id, $added_to);
    }

    public static function boot()
    {
        parent::boot();

        static::saved(function ($project) {
            \Cache::forget('dms_project_collections-'.$project->user_id);
            
            $affected_users = $project->users()->get();
            
            foreach ($affected_users as $u) {
                \Cache::forget('dms_project_collections-'.$u->id);
            }
            
            return $project;
        });
    }
}
