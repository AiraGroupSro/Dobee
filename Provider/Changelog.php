<?php

namespace AiraGroupSro\Dobee\Provider;

use AiraGroupSro\Dobee\Provider\Provider;
use AiraGroupSro\Dobee\Provider\Version;

class Changelog {

	protected $provider;
	protected $entityName;
	protected $entityId;
	protected $versions;
	protected array $versionsByDate;

	public function __construct(Provider $provider,$entityName,$entityId){
		$this->provider = $provider;
		$this->entityName = $entityName;
		$this->entityId = $entityId;
		$this->versions = null;
		$this->versionsByDate = [];
	}

	protected function prepareBlameable($result){
		if(intval($result['blame']) > 0){
			$result['blame'] = $this->provider->fetchBlame($this->entityName,$result['blame']);
		}
		return $result;
	}

	public function getVersion($id){

		if(null !== $this->versions && true === isset($this->versions[$id])){
			return $this->versions[$id];
		}
		else{
			$query = "SELECT * FROM log_storage WHERE entity_class = ? AND entity_id = ? AND id = ? LIMIT 0,1";
			$types = ['s','i','i'];
			$params = [
				$this->entityName,
				(int) $this->entityId,
				(int) $id,
			];
			$result = $this->provider->execute($query,$types,$params);

			if(is_array($result) && count($result)){
				return new Version($this->prepareBlameable($result[0]));
			}

			return null;
		}
	}

	public function getVersions(){

		if(null === $this->versions || false === is_array($this->versions)){
			$query = "SELECT * FROM log_storage WHERE entity_class = ? AND entity_id = ? ORDER BY id DESC";
			$types = ['s','i'];
			$params = [
				$this->entityName,	/// entity_class
				(int) $this->entityId,	/// entity_id
			];
			$result = $this->provider->execute($query,$types,$params);

			$this->versions = [];
			if(is_array($result) && count($result)){
				foreach ($result as $rowData) {
					$this->versions[$rowData['id']] = new Version($this->prepareBlameable($rowData));
				}
			}
		}

		return $this->versions;
	}


	public function getVersionByDatetime(\DateTime $datetime)
	{
		$date = $datetime->format('Y-m-d H:i:s');

		if (!isset($this->versionsByDate[$date])) {
			$query = "SELECT * FROM log_storage WHERE entity_class = ? AND entity_id = ? AND logged_at = ? ORDER BY id DESC";
			$types = ['s', 'i', 's'];
			$params = [
				$this->entityName,
				(int)$this->entityId,
				$date
			];
			$result = $this->provider->execute($query, $types, $params);

			if (is_array($result) && count($result)) {
				$this->versionsByDate[$date] = new Version($this->prepareBlameable(reset($result)));
			}
		}

		return $this->versionsByDate[$date];
	}
}
