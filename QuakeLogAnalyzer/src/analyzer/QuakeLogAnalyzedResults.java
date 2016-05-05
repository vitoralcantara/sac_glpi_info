package analyzer;

import java.util.Map;

public class QuakeLogAnalyzedResults {

	Map<String, Long> kills;
	Map<String, Long> killByMeans;
	long totalKills;

	public QuakeLogAnalyzedResults(long totalKills, Map<String, Long> kills,
			Map<String, Long> killByMeans) {
		this.totalKills = totalKills;
		this.kills = kills;
		this.killByMeans = killByMeans;
	}

	public Map<String, Long> getKills() {
		return kills;
	}

	public Map<String, Long> getKillByMeans() {
		return killByMeans;
	}

	public long getTotalKills() {
		return totalKills;
	}

}
